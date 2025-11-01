<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Link;
use Illuminate\Http\Request;

class AdminLinkController extends Controller
{
    // 🔹 Ambil link user dengan pagination
    public function index(Request $request)
    {
        $perPage = $request->input('per_page', 10); // default 10
        $links = Link::with('user:id,name,email')
            ->latest()
            ->paginate($perPage);

        return response()->json($links);
    }

    // 🔹 Update link
    public function update(Request $request, $id)
    {
        $request->validate([
            'status' => 'in:active,disabled',
            'admin_comment' => 'nullable|string|max:1000',
        ]);

        $link = Link::findOrFail($id);

        $link->update([
            'status' => $request->status ?? $link->status,
            'admin_comment' => $request->admin_comment ?? $link->admin_comment,
        ]);

        $link->load('user:id,name,email'); // pastikan relasi tetap ada

        return response()->json([
            'message' => 'Link updated successfully',
            'link' => $link
        ]);
    }

    // 🔹 Hapus link
    public function destroy($id)
    {
        $link = Link::findOrFail($id);
        $link->delete();

        return response()->json(['message' => 'Link deleted successfully']);
    }
}
