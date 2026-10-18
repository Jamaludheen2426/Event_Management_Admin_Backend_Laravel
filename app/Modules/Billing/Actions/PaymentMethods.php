<?php

declare(strict_types=1);

namespace App\Modules\Billing\Actions;

use App\Core\Exceptions\ApiException;
use App\Core\Support\Dates;
use App\Modules\Billing\Models\PaymentMethod;
use App\Modules\Clients\Models\Client;
use Illuminate\Support\Facades\DB;

/**
 * How a client pays (v1 clientPaymentMethod.service).
 *
 * A card is only ever a payment provider's token: the provider takes the card
 * in the browser. Anything that looks like a card number or CVC is refused,
 * so card data never reaches this server or its logs. Without a provider the
 * client can still record a manual method (UPI, bank transfer, cash) for us to
 * match their payment against.
 */
final class PaymentMethods
{
    public const MAX_METHODS = 5;

    private const MANUAL = ['upi' => 'UPI', 'bank_transfer' => 'Bank transfer', 'cash' => 'Cash'];

    private const CARDISH_KEY = '/^(card_number|card_no|cardnumber|pan|cvv|cvc|card_cvv|security_code|expiry|exp_date)$/i';

    /** @return array<string, mixed> */
    public function list(Client $client): array
    {
        $methods = PaymentMethod::query()->where('client_id', $client->id)->orderByDesc('is_default')->orderByDesc('created_at')->orderByDesc('id')->get()
            ->map(fn (PaymentMethod $m): array => $this->present($m))->values();
        $gateway = $this->gateway();

        return [
            'methods' => $methods->all(),
            'default_method' => $methods->firstWhere('is_default', true),
            'max_methods' => self::MAX_METHODS,
            'can_add' => $methods->count() < self::MAX_METHODS,
            'gateway' => ['enabled' => $gateway['enabled'], 'name' => $gateway['name'], 'publishable_key' => $gateway['publishable_key'], 'reason' => $gateway['reason']],
            'manual' => [
                'enabled' => true,
                'types' => array_map(static fn (string $v, string $l): array => ['value' => $v, 'label' => $l], array_keys(self::MANUAL), self::MANUAL),
                'reason' => 'This records how you pay so we can match your payment when it arrives. Nothing is charged automatically, and we never hold your card details.',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function add(Client $client, array $input): array
    {
        $this->assertNoRawCard($input);
        $count = PaymentMethod::query()->where('client_id', $client->id)->count();
        if ($count >= self::MAX_METHODS) {
            throw ApiException::badRequest('You can save up to '.self::MAX_METHODS.' payment methods. Remove one before adding another.');
        }

        $token = trim($this->text($input['gateway_payment_method_id'] ?? $input['token'] ?? null));
        if ($token !== '') {
            $gateway = $this->gateway();
            if (! $gateway['enabled']) {
                // The request is fine; the capability is missing.
                throw ApiException::serviceUnavailable((string) $gateway['reason']);
            }
            $fields = [
                'gateway' => $gateway['name'],
                'gateway_customer_id' => $this->text($input['gateway_customer_id'] ?? null) ?: null,
                'gateway_payment_method_id' => $token,
                'brand' => mb_substr(mb_strtolower($this->text($input['brand'] ?? null)), 0, 30) ?: null,
                'last4' => substr((string) preg_replace('/\D/', '', $this->text($input['last4'] ?? null)), -4) ?: null,
                'exp_month' => is_numeric($input['exp_month'] ?? null) ? (int) $input['exp_month'] : null,
                'exp_year' => is_numeric($input['exp_year'] ?? null) ? (int) $input['exp_year'] : null,
                'holder_name' => mb_substr($this->text($input['holder_name'] ?? null), 0, 120) ?: null,
                'method_type' => $this->text($input['method_type'] ?? null) ?: 'card',
                'is_verified' => true,
            ];
        } else {
            $fields = [...$this->manual($input), 'gateway' => 'manual', 'is_verified' => false];
            $this->assertNotDuplicate($client, $fields);
        }

        $makeDefault = $count === 0 || in_array($input['is_default'] ?? null, [true, 'true'], true);

        return DB::transaction(function () use ($client, $fields, $makeDefault): array {
            if ($makeDefault) {
                PaymentMethod::query()->where('client_id', $client->id)->update(['is_default' => false]);
            }

            return $this->present(PaymentMethod::query()->create([...$fields, 'client_id' => $client->id, 'is_default' => $makeDefault]));
        });
    }

    /** @return array<string, mixed> */
    public function setDefault(Client $client, int $id): array
    {
        $method = $this->find($client, $id);
        if ($method->isExpired()) {
            throw ApiException::badRequest('That card has expired. Add a current one to use as your default.');
        }
        DB::transaction(static function () use ($client, $method): void {
            PaymentMethod::query()->where('client_id', $client->id)->update(['is_default' => false]);
            $method->forceFill(['is_default' => true])->save();
        });

        return $this->list($client);
    }

    /** @return array<string, mixed> */
    public function remove(Client $client, int $id): array
    {
        $method = $this->find($client, $id);
        $wasDefault = $method->is_default;

        DB::transaction(static function () use ($client, $method, $wasDefault): void {
            $method->forceFill(['is_default' => false, 'status' => 'removed'])->save();
            $method->delete();
            if ($wasDefault) {
                $next = PaymentMethod::query()->where('client_id', $client->id)->orderByDesc('created_at')->orderByDesc('id')->first();
                if ($next !== null && ! $next->isExpired()) {
                    $next->forceFill(['is_default' => true])->save();
                }
            }
        });

        return $this->list($client);
    }

    /**
     * Which provider (if any) can hold cards: Razorpay, then Stripe.
     *
     * @return array{enabled: bool, name: string|null, publishable_key: string|null, reason: string|null}
     */
    public function gateway(): array
    {
        foreach (['razorpay' => ['key_id', 'key_id'], 'stripe' => ['secret', 'key']] as $name => [$secret, $public]) {
            if (trim((string) config("services.{$name}.{$secret}")) !== '') {
                return ['enabled' => true, 'name' => $name, 'publishable_key' => ((string) config("services.{$name}.{$public}")) ?: null, 'reason' => null];
            }
        }

        return [
            'enabled' => false,
            'name' => null,
            'publishable_key' => null,
            'reason' => 'No payment provider is connected, so a card cannot be saved here — a card is held by the provider, never by us. You can still record how you pay, and we will match your payment against it.',
        ];
    }

    /** @return array<string, mixed> */
    public function present(PaymentMethod $m): array
    {
        $expired = $m->isExpired();

        return [
            'id' => $m->id,
            'gateway' => $m->gateway,
            'method_type' => $m->method_type,
            'brand' => $m->brand,
            'last4' => $m->last4,
            'upi_id' => $m->upi_id,
            'bank_name' => $m->bank_name,
            'account_last4' => $m->account_last4,
            'ifsc' => $m->ifsc,
            'is_verified' => $m->is_verified,
            'is_chargeable' => $m->gateway !== 'manual',
            'exp_month' => $m->exp_month,
            'exp_year' => $m->exp_year,
            'expiry_label' => $m->exp_month !== null && $m->exp_year !== null ? sprintf('%02d/%s', $m->exp_month, substr((string) $m->exp_year, -2)) : null,
            'holder_name' => $m->holder_name,
            'is_default' => $m->is_default,
            'is_expired' => $expired,
            'status' => $expired && $m->status === 'active' ? 'expired' : $m->status,
            'label' => $m->label(),
            'type_label' => self::MANUAL[$m->method_type] ?? 'Card',
            'created_at' => Dates::at($m->created_at),
        ];
    }

    private function find(Client $client, int $id): PaymentMethod
    {
        return PaymentMethod::query()->where('client_id', $client->id)->find($id) ?? throw ApiException::notFound('Payment method not found.');
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function manual(array $input): array
    {
        $type = mb_strtolower(trim($this->text($input['method_type'] ?? null)));
        if (! array_key_exists($type, self::MANUAL)) {
            throw ApiException::badRequest('Choose how you pay: '.implode(', ', self::MANUAL).'.');
        }
        $holder = mb_substr(trim($this->text($input['holder_name'] ?? null)), 0, 120) ?: null;

        if ($type === 'cash') {
            return ['method_type' => 'cash', 'holder_name' => $holder];
        }
        if ($type === 'upi') {
            $upi = trim($this->text($input['upi_id'] ?? null));
            if ($upi === '') {
                throw ApiException::badRequest('Enter your UPI ID.');
            }
            if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9.\-_]{1,63}@[a-zA-Z][a-zA-Z0-9]{1,31}$/', $upi) !== 1) {
                throw ApiException::badRequest('That does not look like a UPI ID. It should look like name@bank.');
            }

            return ['method_type' => 'upi', 'upi_id' => mb_strtolower($upi), 'holder_name' => $holder];
        }

        $bank = trim($this->text($input['bank_name'] ?? null));
        if ($bank === '') {
            throw ApiException::badRequest('Enter the name of your bank.');
        }
        // Exactly four digits; a longer value is refused, not trimmed, so a
        // full account number is never accepted.
        $last4 = (string) preg_replace('/[\s-]/', '', $this->text($input['account_last4'] ?? null));
        if (preg_match('/^\d{4}$/', $last4) !== 1) {
            throw ApiException::badRequest(strlen($last4) > 4
                ? 'Enter only the LAST 4 digits of your account number, not the whole number.'
                : 'Enter the last 4 digits of your account number.');
        }
        $ifsc = mb_strtoupper(trim($this->text($input['ifsc'] ?? null)));
        if ($ifsc !== '' && preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', $ifsc) !== 1) {
            throw ApiException::badRequest('That is not a valid IFSC. It looks like HDFC0001234.');
        }

        return ['method_type' => 'bank_transfer', 'bank_name' => mb_substr($bank, 0, 120), 'account_last4' => $last4, 'ifsc' => $ifsc ?: null, 'holder_name' => $holder];
    }

    /** @param array<string, mixed> $fields */
    private function assertNotDuplicate(Client $client, array $fields): void
    {
        $query = PaymentMethod::query()->where('client_id', $client->id);
        match ($fields['method_type']) {
            'cash' => $query->where('method_type', 'cash'),
            'upi' => $query->where('upi_id', $fields['upi_id']),
            default => $query->where('account_last4', $fields['account_last4'])->where('bank_name', $fields['bank_name']),
        };
        if ($query->exists()) {
            throw ApiException::badRequest($fields['method_type'] === 'cash'
                ? 'You have already saved Cash as a payment method.'
                : 'You have already saved that payment method.');
        }
    }

    /** @param array<string, mixed> $input */
    private function assertNoRawCard(array $input): void
    {
        foreach ($input as $key => $value) {
            if (preg_match(self::CARDISH_KEY, (string) $key) === 1) {
                throw ApiException::badRequest('Card details must never be sent to this server. The payment provider takes the card in your browser and returns a token; send that token instead.');
            }
            if (is_string($value)) {
                $digits = (string) preg_replace('/[\s-]/', '', $value);
                if (preg_match('/^\d{13,19}$/', $digits) === 1 && $this->luhn($digits)) {
                    throw ApiException::badRequest('That looks like a card number. Card details must never be sent to this server — send the token the payment provider gives your browser.');
                }
            }
        }
    }

    private function luhn(string $digits): bool
    {
        $sum = 0;
        $double = false;
        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $d = (int) $digits[$i];
            if ($double) {
                $d *= 2;
                if ($d > 9) {
                    $d -= 9;
                }
            }
            $sum += $d;
            $double = ! $double;
        }

        return $sum % 10 === 0;
    }

    private function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
