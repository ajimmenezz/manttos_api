<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json($request->user()->load('roles', 'permissions'));
    }

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        $request->validate([
            'name'  => 'required|string|max:255',
            'email' => "required|email|unique:users,email,{$user->id}",
        ]);

        $user->update($request->only('name', 'email'));

        return response()->json(['message' => 'Perfil actualizado correctamente.', 'user' => $user]);
    }
}
