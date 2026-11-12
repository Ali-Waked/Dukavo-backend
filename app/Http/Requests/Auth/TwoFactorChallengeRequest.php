<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Validator;

/**
 * The second step of a two-factor login.
 *
 * The challenge token is the only thing that ties this request to the password
 * that was already verified. It is random, short-lived and single-use; the
 * database stores only its hash. A caller must supply either a TOTP code or a
 * recovery code, never neither.
 */
class TwoFactorChallengeRequest extends ApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'challenge_token' => ['required', 'string', 'max:128'],
            'code' => ['nullable', 'string', 'max:16'],
            'recovery_code' => ['nullable', 'string', 'max:32'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $code = trim((string) $this->input('code', ''));
            $recovery = trim((string) $this->input('recovery_code', ''));

            if ($code === '' && $recovery === '') {
                $validator->errors()->add('code', 'Enter an authentication code or a recovery code.');
            }
        });
    }
}
