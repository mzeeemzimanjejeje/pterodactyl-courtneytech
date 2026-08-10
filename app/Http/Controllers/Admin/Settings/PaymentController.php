<?php

namespace Pterodactyl\Http\Controllers\Admin\Settings;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Prologue\Alerts\AlertsMessageBag;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Contracts\Repository\SettingsRepositoryInterface;

class PaymentController extends Controller
{
    /**
     * Configuration keys used by the wallet payment integrations.
     * Secret values are encrypted before they are saved to the settings table.
     */
    private const FIELDS = [
        'services:paystack:public_key' => false,
        'services:paystack:secret_key' => true,
        'services:courtneytech:base_url' => false,
        'services:courtneytech:api_key' => true,
        'services:courtneytech:api_secret' => true,
        'services:courtneytech:account_id' => false,
    ];

    public function __construct(
        private AlertsMessageBag $alert,
        private Kernel $kernel,
        private Encrypter $encrypter,
        private SettingsRepositoryInterface $settings,
    ) {
    }

    public function index(): View
    {
        $stored = $this->settings->all()->mapWithKeys(fn ($setting) => [$setting->key => $setting->value])->toArray();

        $configured = [];
        $configKeys = [
            'services:paystack:public_key' => 'services.paystack.public_key',
            'services:paystack:secret_key' => 'services.paystack.secret_key',
            'services:courtneytech:base_url' => 'services.courtneytech.base_url',
            'services:courtneytech:api_key' => 'services.courtneytech.api_key',
            'services:courtneytech:api_secret' => 'services.courtneytech.api_secret',
            'services:courtneytech:account_id' => 'services.courtneytech.account_id',
        ];

        foreach (array_keys(self::FIELDS) as $key) {
            $configured[$key] = (isset($stored['settings::' . $key]) && $stored['settings::' . $key] !== '')
                || (string) config($configKeys[$key], '') !== '';
        }

        return view('admin.settings.payment', compact('configured'));
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'paystack_public_key' => ['nullable', 'string', 'max:255'],
            'paystack_secret_key' => ['nullable', 'string', 'max:255'],
            'courtney_base_url' => ['nullable', 'url', 'max:255'],
            'courtney_api_key' => ['nullable', 'string', 'max:255'],
            'courtney_api_secret' => ['nullable', 'string', 'max:255'],
            'courtney_account_id' => ['nullable', 'string', 'max:100'],
        ]);

        $mapping = [
            'paystack_public_key' => 'services:paystack:public_key',
            'paystack_secret_key' => 'services:paystack:secret_key',
            'courtney_base_url' => 'services:courtneytech:base_url',
            'courtney_api_key' => 'services:courtneytech:api_key',
            'courtney_api_secret' => 'services:courtneytech:api_secret',
            'courtney_account_id' => 'services:courtneytech:account_id',
        ];

        foreach ($mapping as $input => $key) {
            // Blank fields mean “leave the existing value unchanged”, preventing
            // a masked/empty form field from accidentally deleting a live key.
            if (!isset($data[$input]) || trim((string) $data[$input]) === '') {
                continue;
            }

            $value = trim((string) $data[$input]);
            if (self::FIELDS[$key]) {
                $value = $this->encrypter->encrypt($value);
            }

            $this->settings->set('settings::' . $key, $value);
        }

        $this->kernel->call('queue:restart');
        $this->alert->success('Payment API settings have been updated. The configured gateways are now available to users.')->flash();

        return redirect()->route('admin.settings.payment');
    }
}
