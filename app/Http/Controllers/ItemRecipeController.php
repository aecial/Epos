<?php

namespace App\Http\Controllers;

use App\Http\Requests\Item\UpdateItemRecipeRequest;
use App\Models\Item;
use App\Services\ItemRecipeService;

class ItemRecipeController extends Controller
{
    protected ItemRecipeService $itemRecipeService;

    public function __construct(ItemRecipeService $itemRecipeService)
    {
        $this->itemRecipeService = $itemRecipeService;
    }

    public function getItemRecipe(Item $item)
    {
        return $this->itemRecipeService->ReadItemRecipe($item);
    }

    public function updateItemRecipe(UpdateItemRecipeRequest $request, Item $item)
    {
        return $this->itemRecipeService->ReplaceItemRecipe($item, $request->validated('ingredients'));
    }
}
