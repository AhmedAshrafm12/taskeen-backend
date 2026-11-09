<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $input = $request->validate([
            'login' => 'required_without:email|string|max:255',
            'email' => 'required_without:login|string|max:255',
            'password' => 'required|string|max:255',
        ]);

        $identifier = trim((string) ($input['login'] ?? $input['email']));
        $user = User::query()
            ->where('email', $identifier)
            ->orWhere('username', mb_strtolower($identifier))
            ->first();

        if (! $user || ! Hash::check($input['password'], $user->password)) {
            $field = array_key_exists('login', $input) ? 'login' : 'email';
            throw ValidationException::withMessages([
                $field => ['اسم المستخدم أو البريد أو كلمة المرور غير صحيحة.'],
            ]);
        }

        $expiresAt = now()->addHours(8);
        $token = $user->createToken('taskeen-web', ['*'], $expiresAt)->plainTextToken;

        return response()->json([
            'data' => [
                'token' => $token,
                'expires_at' => $expiresAt->toIso8601String(),
                'user' => $this->payload($user),
            ],
        ]);
    }

    public function me(Request $request)
    {
        return response()->json(['data' => $this->payload($request->user())]);
    }

    public function update(Request $request)
    {
        $user = $request->user();

        $input = $request->validate([
            'name' => 'required|string|max:120',
            'username' => [
                'required',
                'string',
                'min:3',
                'max:50',
                'regex:/^[A-Za-z][A-Za-z0-9._-]*$/',
                Rule::unique('users', 'username')->ignore($user->id),
            ],
            'current_password' => 'nullable|required_with:password|string',
            'password' => 'nullable|string|min:8|max:255|confirmed',
        ], [
            'username.regex' => 'اسم المستخدم يبدأ بحرف إنجليزي ويمكن أن يحتوي أحرفًا وأرقامًا و . _ - فقط.',
            'password.confirmed' => 'تأكيد كلمة المرور غير متطابق.',
            'password.min' => 'كلمة المرور الجديدة يجب ألا تقل عن 8 أحرف.',
        ]);

        $username = mb_strtolower(trim($input['username']));

        if (! empty($input['password'])) {
            if (! Hash::check((string) ($input['current_password'] ?? ''), $user->password)) {
                throw ValidationException::withMessages([
                    'current_password' => ['كلمة المرور الحالية غير صحيحة.'],
                ]);
            }
            $user->password = $input['password'];
            $currentToken = $request->user()->currentAccessToken();
            $currentId = is_object($currentToken) && isset($currentToken->id) ? $currentToken->id : null;
            $otherTokens = $user->tokens();
            if ($currentId) {
                $otherTokens->where('id', '!=', $currentId);
            }
            $otherTokens->delete();
        }

        $user->fill([
            'name' => trim($input['name']),
            'username' => $username,
        ])->save();

        return response()->json([
            'message' => 'تم تحديث بيانات الحساب.',
            'data' => $this->payload($user->fresh()),
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'تم تسجيل الخروج.']);
    }

    private function payload(User $user): array
    {
        return $user->only('id', 'name', 'username', 'email');
    }
}
