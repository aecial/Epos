<?php

namespace App\Services;

use App\Exceptions\InsufficientInventoryException;
use App\Models\Ingredient;
use App\Models\Item;
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

    public function DeductItem(Item $item, int $quantity): void
    {
        $this->assertPositiveQuantity($quantity);

        DB::transaction(function () use ($item, $quantity): void {
            $lockedItem = $this->lockItem($item);

            match ($lockedItem->inventory_type) {
                'direct' => $this->deductDirectItem($lockedItem, $quantity),
                'recipe' => $this->deductRecipeItem($lockedItem, $quantity),
                'none' => null,
                default => throw new InvalidArgumentException('Unsupported inventory type.'),
            };
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

    public function AvailableForItem(Item $item): float
    {
        $freshItem = Item::query()->findOrFail($item->id);

        return match ($freshItem->inventory_type) {
            'direct' => max(0, (int) $freshItem->quantity - (int) $freshItem->reserved_quantity),
            'recipe' => $this->availableRecipeQuantity($freshItem),
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

    private function deductRecipeItem(Item $item, int $quantity): void
    {
        $requirements = $this->lockedRequirements($item);
        $this->assertRecipeAvailability($requirements, $quantity);

        foreach ($requirements as $requirement) {
            $ingredient = $requirement['ingredient'];
            $required = $requirement['quantity'] * $quantity;

            if ((float) $ingredient->reserved_quantity + 0.000001 < $required) {
                throw new InsufficientInventoryException("Insufficient reserved stock for ingredient {$ingredient->name}.");
            }

            $ingredient->quantity = $this->roundQuantity((float) $ingredient->quantity - $required);
            $ingredient->reserved_quantity = $this->roundQuantity((float) $ingredient->reserved_quantity - $required);
            $ingredient->save();
        }
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
        $recipes = $item->ingredients()->get()->sortBy('id');
        $requirements = [];

        foreach ($recipes as $recipe) {
            $ingredient = Ingredient::query()->lockForUpdate()->findOrFail($recipe->id);
            $requirements[] = [
                'ingredient' => $ingredient,
                'quantity' => (float) $recipe->pivot->quantity_required,
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

    private function availableRecipeQuantity(Item $item): float
    {
        $requirements = $item->ingredients()->get();
        $availableServings = INF;

        foreach ($requirements as $recipe) {
            $available = (float) $recipe->quantity - (float) $recipe->reserved_quantity;
            $required = (float) $recipe->pivot->quantity_required;
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
