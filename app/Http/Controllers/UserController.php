<?php

// app/Http/Controllers/UserController.php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\Bitrix24Service;

class UserController extends Controller
{
    protected $bitrix24Service;

    public function __construct(Bitrix24Service $bitrix24Service)
    {
        $this->bitrix24Service = $bitrix24Service;
    }

    public function register(Request $request)
    {
        $userData = [
            'fields' => [
                'NAME' => $request->input('name'),
                'EMAIL' => $request->input('email'),
                'PASSWORD' => $request->input('password'),
            ],
        ];
        $user = $this->bitrix24Service->addUser($userData);
        return response()->json($user);
    }
}
