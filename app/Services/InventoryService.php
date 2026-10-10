<?php

namespace App\Services;

use App\Exceptions\InsufficientInventoryException;
use App\Models\Ingredient;
use App\Models\Item;
use App\Models\TicketItem;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class InventoryService
{
    public function ReserveItem(Item $item, int $quantity): void
    {
        $this->assertPositiveQuantity($quantity);

        DB::transaction(function () use ($item, $quantity): void {
            $lockedItem = $this->lockItem($item);

            match ($lockedItem->inventory_type) {
                'direct' => $this->reserveDirectItem($lockedItem, $quantity),
                'recipe' => $this->reserveRecipeItem($lockedItem, $quantity),
                'none' => null,
                default => throw new InvalidArgumentException('Unsupported inventory type.'),
            };
        });
    }

    public function ReleaseItem(Item $item, int $quantity): void
    {
        $this->assertPositiveQuantity($quantity);

        DB::transaction(function () use ($item, $quantity): void {
            $lockedItem = $this->lockItem($item);

            match ($lockedItem->inventory_type) {
                'direct' => $this->releaseDirectItem($lockedItem, $quantity),
                'recipe' => $this->releaseRecipeItem($lockedItem, $quantity),
                'none' => null,
                default => throw new InvalidArgumentException('Unsupported inventory type.'),
            };
        });
    }

    /**
     * Takes sold stock out. For a recipe item, returns what each ingredient gave up (the total
     * for $quantity servings) so the caller can record it; direct and untracked items return [].
     *
     * @return array<int, array{ingredient: Ingredient, quantity: float}>
     */
    public function DeductItem(Item $item, int $quantity): array
    {
        $this->assertPositiveQuantity($quantity);

        return DB::transaction(function () use ($item, $quantity): array {
            $lockedItem = $this->lockItem($item);

            if ($lockedItem->inventory_type === 'recipe') {
                return $this->deductRecipeItem($lockedItem, $quantity);
            }

            match ($lockedItem->inventory_type) {
                'direct' => $this->deductDirectItem($lockedItem, $quantity),
                'none' => null,
                default => throw new InvalidArgumentException('Unsupported inventory type.'),
            };

            return [];
        });
    }

    /**
     * Puts back $quantity servings of a paid recipe line using what the line actually took at
     * payment (ticket_item_ingredients), so a recipe edited since then can't change the amount
     * returned. Returns false when the line has no recorded usage (paid before usage was
     * recorded) - the caller falls back to RestoreItem.
     */
    public function RestoreRecordedUsage(TicketItem $line, int $quantity): bool
    {
        $this->assertPositiveQuantity($quantity);

        return DB::transaction(function () use ($line, $quantity): bool {
            $usage = $line->ingredientUsage()->orderBy('ingredient_id')->get();

            if ($usage->isEmpty()) {
                return false;
            }

            // Ascending ingredient id, same lock order as lockedRequirements().
            $ingredients = Ingredient::query()
                ->whereKey($usage->pluck('ingredient_id'))
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($usage as $used) {
                $ingredient = $ingredients[$used->ingredient_id];
                $returned = (float) $used->quantity_used * $quantity / (int) $line->quantity;
                $ingredient->quantity = $this->roundQuantity((float) $ingredient->quantity + $returned);
                $ingredient->save();
            }

            return true;
        });
    }

    public function RestoreItem(Item $item, int $quantity): void
    {
        $this->assertPositiveQuantity($quantity);

        DB::transaction(function () use ($item, $quantity): void {
            $lockedItem = $this->lockItem($item);

            match ($lockedItem->inventory_type) {
                'direct' => $this->restoreDirectItem($lockedItem, $quantity),
                'recipe' => $this->restoreRecipeItem($lockedItem, $quantity),
                'none' => null,
                default => throw new InvalidArgumentException('Unsupported inventory type.'),
            };
        });
    }

    /**
     * Sellable units, re-read from the database. For a single item whose loaded state may be stale.
     */
    public function AvailableForItem(Item $item): float
    {
        return $this->AvailableFromLoaded(Item::query()->with('ingredients')->findOrFail($item->id));
    }

    /**
     * Sellable units computed from the item's already-loaded attributes and `ingredients`
     * relation, without querying. For lists that eager-loaded `ingredients` in the same
     * request. A display figure only: ReserveItem re-checks under row locks.
     */
    public function AvailableFromLoaded(Item $item): float
    {
        return match ($item->inventory_type) {
            'direct' => max(0, (int) $item->quantity - (int) $item->reserved_quantity),
            'recipe' => $this->availableRecipeServings($item),
            'none' => INF,
            default => throw new InvalidArgumentException('Unsupported inventory type.'),
        };
    }

    private function lockItem(Item $item): Item
    {
        return Item::query()->lockForUpdate()->findOrFail($item->id);
    }

    private function reserveDirectItem(Item $item, int $quantity): void
    {
        $available = (int) $item->quantity - (int) $item->reserved_quantity;

        if ($available < $quantity) {
            throw new InsufficientInventoryException("Insufficient stock for item {$item->name}.");
        }

        $item->reserved_quantity += $quantity;
        $item->save();
    }

    private function releaseDirectItem(Item $item, int $quantity): void
    {
        if ((int) $item->reserved_quantity < $quantity) {
            throw new InvalidArgumentException("Cannot release more stock than reserved for item {$item->name}.");
        }

        $item->reserved_quantity -= $quantity;
        $item->save();
    }

    private function deductDirectItem(Item $item, int $quantity): void
    {
        if ((int) $item->quantity < $quantity || (int) $item->reserved_quantity < $quantity) {
            throw new InsufficientInventoryException("Insufficient reserved stock for item {$item->name}.");
        }

        $item->quantity -= $quantity;
        $item->reserved_quantity -= $quantity;
        $item->save();
    }

    private function restoreDirectItem(Item $item, int $quantity): void
    {
        $item->quantity += $quantity;
        $item->save();
    }

    private function reserveRecipeItem(Item $item, int $quantity): void
    {
        $requirements = $this->lockedRequirements($item);
        $this->assertRecipeAvailability($requirements, $quantity);

        foreach ($requirements as $requirement) {
            $ingredient = $requirement['ingredient'];
            $required = $requirement['quantity'] * $quantity;
            $ingredient->reserved_quantity = $this->roundQuantity((float) $ingredient->reserved_quantity + $required);
            $ingredient->save();
        }
    }

    private function releaseRecipeItem(Item $item, int $quantity): void
    {
        $requirements = $this->lockedRequirements($item);

        foreach ($requirements as $requirement) {
            $ingredient = $requirement['ingredient'];
            $required = $requirement['quantity'] * $quantity;

            if ((float) $ingredient->reserved_quantity + 0.000001 < $required) {
                throw new InvalidArgumentException("Cannot release more stock than reserved for ingredient {$ingredient->name}.");
            }

            $ingredient->reserved_quantity = $this->roundQuantity((float) $ingredient->reserved_quantity - $required);
            $ingredient->save();
        }
    }

    /**
     * @return array<int, array{ingredient: Ingredient, quantity: float}>
     */
    private function deductRecipeItem(Item $item, int $quantity): array
    {
        // No free-stock check here: this line's servings are already part of reserved_quantity,
        // so "quantity - reserved" would count them against themselves and refuse the last ones.
        // Like a direct item, it needs the stock on hand and the reservation to cover it.
        $requirements = $this->lockedRequirements($item);
        $deducted = [];

        foreach ($requirements as $requirement) {
            $ingredient = $requirement['ingredient'];
            $required = $requirement['quantity'] * $quantity;

            if ((float) $ingredient->quantity + 0.000001 < $required || (float) $ingredient->reserved_quantity + 0.000001 < $required) {
                throw new InsufficientInventoryException("Insufficient reserved stock for ingredient {$ingredient->name}.");
            }

            $ingredient->quantity = $this->roundQuantity((float) $ingredient->quantity - $required);
            $ingredient->reserved_quantity = $this->roundQuantity((float) $ingredient->reserved_quantity - $required);
            $ingredient->save();

            $deducted[] = ['ingredient' => $ingredient, 'quantity' => $this->roundQuantity($required)];
        }

        return $deducted;
    }

    private function restoreRecipeItem(Item $item, int $quantity): void
    {
        $requirements = $this->lockedRequirements($item);

        foreach ($requirements as $requirement) {
            $ingredient = $requirement['ingredient'];
            $required = $requirement['quantity'] * $quantity;
            $ingredient->quantity = $this->roundQuantity((float) $ingredient->quantity + $required);
            $ingredient->save();
        }
    }

    /**
     * @return array<int, array{ingredient: Ingredient, quantity: float}>
     */
    private function lockedRequirements(Item $item): array
    {
        // Ascending id order keeps lock acquisition consistent across concurrent transactions.
        $ingredients = $item->ingredients()->orderBy('ingredients.id')->lockForUpdate()->get();
        $requirements = [];

        foreach ($ingredients as $ingredient) {
            $requirements[] = [
                'ingredient' => $ingredient,
                'quantity' => (float) $ingredient->pivot->quantity_required,
            ];
        }

        if ($requirements === []) {
            throw new InvalidArgumentException("Recipe item {$item->name} has no ingredients.");
        }

        return $requirements;
    }

    /**
     * @param  array<int, array{ingredient: Ingredient, quantity: float}>  $requirements
     */
    private function assertRecipeAvailability(array $requirements, int $quantity): void
    {
        foreach ($requirements as $requirement) {
            $ingredient = $requirement['ingredient'];
            $required = $requirement['quantity'] * $quantity;
            $available = (float) $ingredient->quantity - (float) $ingredient->reserved_quantity;

            if ($available + 0.000001 < $required) {
                throw new InsufficientInventoryException("Insufficient stock for ingredient {$ingredient->name}.");
            }
        }
    }

    private function availableRecipeServings(Item $item): float
    {
        $availableServings = INF;

        foreach ($item->ingredients as $ingredient) {
            $available = (float) $ingredient->quantity - (float) $ingredient->reserved_quantity;
            $required = (float) $ingredient->pivot->quantity_required;
            $availableServings = min($availableServings, floor(($available + 0.000001) / $required));
        }

        if ($availableServings === INF) {
            throw new InvalidArgumentException("Recipe item {$item->name} has no ingredients.");
        }

        return (float) $availableServings;
    }

    private function assertPositiveQuantity(int $quantity): void
    {
        if ($quantity <= 0) {
            throw new InvalidArgumentException('Quantity must be greater than zero.');
        }
    }

    private function roundQuantity(float $quantity): float
    {
        return round($quantity, 3);
    }
}
