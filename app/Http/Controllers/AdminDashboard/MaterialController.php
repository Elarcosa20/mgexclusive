<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Models\Material;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class MaterialController extends Controller
{
    // Get all materials
    public function index()
    {
        return response()->json(Material::all());
    }

    // Store new material
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'     => 'required|string|max:255',
            'type'     => 'required|string|max:255',
            'quantity' => 'required|integer|min:0',
            'cost'     => 'required|numeric|min:0',
            'image'    => 'nullable|image|mimes:jpg,jpeg,png|max:2048',
        ]);

        if ($request->hasFile('image')) {
            $path = $request->file('image')->store('materials', 'public');
            $validated['image'] = $path;
        }

        $material = Material::create($validated);

        return response()->json($material, 201);
    }

    // Update existing material
    public function update(Request $request, Material $material)
    {
        $validated = $request->validate([
            'name'     => 'sometimes|required|string|max:255',
            'type'     => 'sometimes|required|string|max:255',
            'quantity' => 'sometimes|required|integer|min:0',
            'cost'     => 'sometimes|required|numeric|min:0',
            'image'    => 'nullable|image|mimes:jpg,jpeg,png|max:2048',
        ]);

        if ($request->hasFile('image')) {
            if ($material->image) {
                Storage::disk('public')->delete($material->image);
            }
            $path = $request->file('image')->store('materials', 'public');
            $validated['image'] = $path;
        }

        $material->update($validated);

        return response()->json($material);
    }

    // Delete material
    public function destroy(Material $material)
    {
        if ($material->image) {
            Storage::disk('public')->delete($material->image);
        }

        $material->delete();

        return response()->json(['message' => 'Material deleted successfully']);
    }

    // Restock
    public function restock(Request $request, Material $material)
    {
        $validated = $request->validate([
            'restock' => 'nullable|integer|min:0',
            'used'    => 'nullable|integer|min:0',
        ]);

        $restock = $validated['restock'] ?? 0;
        $used    = $validated['used'] ?? 0;

        $material->quantity += $restock;
        $material->quantity -= $used;
        if ($material->quantity < 0) {
            $material->quantity = 0;
        }

        $material->save();

        return response()->json($material);
    }
}
