<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use App\Models\EmailOtp;                  // ← pour le modèle EmailOtp
use Illuminate\Support\Facades\Mail;      // ← pour la facade Mail

class AuthController extends Controller
{
    public function register(Request $request)
{
    $request->validate([
        'name' => 'required|string|max:255',
        'email' => 'required|string|email|max:255|unique:users',
        'password' => 'required|string|min:8',
    ]);

    // Créer l'utilisateur mais sans email vérifié
    $user = User::create([
        'name' => $request->name,
        'email' => $request->email,
        'password' => Hash::make($request->password),
        'email_verified_at' => null,  // Pas encore vérifié
    ]);

    // Générer un code OTP à 6 chiffres (aléatoire)
    $otpCode = rand(100000, 999999);  // 6 chiffres entre 100000 et 999999

    // Enregistrer l'OTP dans la table
    $otp = EmailOtp::create([
        'user_id'     => $user->id,
        'code'        => $otpCode,
        'expires_at'  => now()->addMinutes(30),  // ← 30 minutes d'expiration
        'is_used'     => false,
    ]);

    // Envoyer l'email avec le code
    Mail::raw("Votre code de vérification est : $otpCode\n\nCe code expire dans 30 minutes.", function ($message) use ($user) {
        $message->to($user->email)
                ->subject('Vérification de votre email - Memoire App');
    });

    // Réponse à l'utilisateur (pas de token pour l'instant)
    return response()->json([
        'message' => 'Inscription réussie ! Vérifiez votre email pour activer votre compte.',
        'user'    => $user,
        // Pas de token ici
    ], 201);
}

    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Les identifiants sont incorrects.'],
            ]);
        }

        $token = $user->createToken('flutter-app')->plainTextToken;

        return response()->json([
            'message' => 'Connexion réussie',
            'user' => $user,
            'token' => $token
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Déconnexion réussie']);
    }

    public function verifyEmail(Request $request)
    {
        $request->validate([
            'email' => 'required|email|exists:users,email',
            'code'  => 'required|string|size:6',
        ]);

        $user = User::where('email', $request->email)->first();

        // Cherche le dernier OTP non utilisé pour cet utilisateur
        $otp = $user->emailOtps()
                    ->where('code', $request->code)
                    ->where('is_used', false)
                    ->where('expires_at', '>', now())
                    ->latest()
                    ->first();

        if (!$otp) {
            return response()->json([
                'message' => 'Code OTP invalide, expiré ou déjà utilisé.',
            ], 422);
        }

        // Marquer le code comme utilisé
        $otp->update(['is_used' => true]);

        // Valider l'email de l'utilisateur
        $user->update(['email_verified_at' => now()]);
        dd(now());

        $user = $user->fresh();  // ← RAFRAÎCHIR L'OBJET $USER

        // Générer un token maintenant que c'est vérifié
        $token = $user->createToken('mobile-app-token')->plainTextToken;

        return response()->json([
            'message' => 'Email vérifié avec succès ! Vous pouvez maintenant vous connecter.',
            'user'    => $user,
            'token'   => $token,
        ]);
    }
}

