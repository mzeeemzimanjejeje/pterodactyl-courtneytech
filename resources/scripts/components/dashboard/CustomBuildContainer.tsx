import React, { useEffect, useMemo, useRef, useState } from 'react';
import { useHistory } from 'react-router-dom';
import Spinner from '@/components/elements/Spinner';
import Button from '@/components/elements/Button';
import { Dialog } from '@/components/elements/dialog';
import http from '@/api/http';
import tw from 'twin.macro';

declare global {
    interface Window {
        PaystackPop?: { setup: (options: Record<string, any>) => { openIframe: () => void } };
    }
}
interface PriceEntry {
    price_kes: number;
    unit_label: string;
}
interface Options {
    nests: { id: number; name: string }[];
    eggs: { id: number; nest_id: number; name: string }[];
    prices: Partial<Record<'ram' | 'disk' | 'cpu' | 'database' | 'backup' | 'allocation', PriceEntry>>;
    price_cap: number;
}
interface Config {
    nestId: number | null;
    eggId: number | null;
    memory: number;
    disk: number;
    cpu: number;
    databases: number;
    backups: number;
    allocations: number;
}
const DEFAULT_CONFIG: Config = {
    nestId: null,
    eggId: null,
    memory: 512,
    disk: 1024,
    cpu: 50,
    databases: 0,
    backups: 0,
    allocations: 1,
};
const loadPaystackScript = (): Promise<void> =>
    new Promise((resolve, reject) => {
        if (window.PaystackPop) return resolve();
        const existing = document.getElementById('paystack-inline-js') as HTMLScriptElement | null;
        if (existing) {
            existing.addEventListener('load', () => resolve());
            existing.addEventListener('error', () => reject(new Error('Failed to load Paystack.')));
            return;
        }
        const script = document.createElement('script');
        script.id = 'paystack-inline-js';
        script.src = 'https://js.paystack.co/v1/inline.js';
        script.async = true;
        script.onload = () => resolve();
        script.onerror = () => reject(new Error('Failed to load Paystack.'));
        document.head.appendChild(script);
    });

export default () => {
    const history = useHistory();
    const [options, setOptions] = useState<Options | null>(null);
    const [config, setConfig] = useState<Config>(DEFAULT_CONFIG);
    const [serverName, setServerName] = useState('');
    const [phone, setPhone] = useState('');
    const [purchasing, setPurchasing] = useState(false);
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const timer = useRef<ReturnType<typeof setTimeout> | null>(null);
    useEffect(() => {
        http.get('/account/store/custom/options')
            .then(({ data }) => {
                setOptions(data);
                const nest = data.nests?.[0];
                const egg = data.eggs?.find((item: any) => item.nest_id === nest?.id);
                if (nest && egg) setConfig((c) => ({ ...c, nestId: nest.id, eggId: egg.id }));
            })
            .catch((e) => setError(e?.response?.data?.error || 'Unable to load custom server options.'));
        return () => {
            if (timer.current) clearTimeout(timer.current);
        };
    }, []);
    const priceOf = (key: keyof Options['prices']) => options?.prices[key]?.price_kes ?? 0;
    const total = useMemo(
        () =>
            options
                ? Math.round(
                      ((config.memory / 1024) * priceOf('ram') +
                          (config.disk / 1024) * priceOf('disk') +
                          (config.cpu / 100) * priceOf('cpu') +
                          config.databases * priceOf('database') +
                          config.backups * priceOf('backup') +
                          config.allocations * priceOf('allocation')) *
                          100
                  ) / 100
                : 0,
        [config, options]
    );
    const updateField = (field: keyof Config, value: number) =>
        setConfig((c) => ({ ...c, [field]: field === 'allocations' ? Math.max(1, value) : Math.max(0, value) }));
    const poll = (reference: string, attempt = 0): Promise<any> =>
        new Promise((resolve) => {
            http.get(`/account/store/payment/status/${reference}`)
                .then(({ data }) => {
                    if (['success', 'failed', 'confirmed_provisioning_failed'].includes(data.status) || attempt >= 40)
                        return resolve(data);
                    timer.current = setTimeout(() => poll(reference, attempt + 1).then(resolve), 3000);
                })
                .catch(() => {
                    if (attempt >= 40) return resolve({ status: 'failed' });
                    timer.current = setTimeout(() => poll(reference, attempt + 1).then(resolve), 3000);
                });
        });
    const finish = (data: any) => {
        setPurchasing(false);
        setConfirmOpen(false);
        if (data.status === 'success' && data.server_id) history.push(`/server/${data.server_id}`);
        else
            setError(
                data.status === 'confirmed_provisioning_failed'
                    ? 'Payment was confirmed, but provisioning failed. Please contact support.'
                    : 'We could not confirm this payment.'
            );
    };
    const onPurchase = async () => {
        if (!config.nestId || !config.eggId || !serverName.trim()) return;
        setPurchasing(true);
        setError(null);
        try {
            const { data } = await http.post('/account/store/payment/initialize', {
                server_name: serverName.trim(),
                nest_id: config.nestId,
                egg_id: config.eggId,
                memory: config.memory,
                disk: config.disk,
                cpu: config.cpu,
                databases: config.databases,
                backups: config.backups,
                allocations: config.allocations,
                phone: phone.trim() || undefined,
            });
            if (data.gateway === 'courtneytech') {
                finish(await poll(data.reference));
                return;
            }
            await loadPaystackScript();
            if (!window.PaystackPop) throw new Error('Paystack failed to load. Please refresh and try again.');
            window.PaystackPop.setup({
                key: data.public_key,
                email: data.email,
                amount: data.amount,
                currency: 'KES',
                ref: data.reference,
                channels: ['card'],
                callback: (response: { reference?: string }) => poll(response.reference || data.reference).then(finish),
                onClose: () => setPurchasing(false),
            }).openIframe();
        } catch (e: any) {
            setPurchasing(false);
            setError(e?.response?.data?.error || e.message || 'Unable to initialize payment.');
        }
    };
    if (!options) return <Spinner centered size={'large'} />;
    const usagePercent = options.price_cap > 0 ? Math.min(100, (total / options.price_cap) * 100) : 0;
    return (
        <div>
            <div css={tw`bg-neutral-800 border border-neutral-700 rounded-lg p-6`}>
                <div css={tw`mb-5`}>
                    <label css={tw`text-xs uppercase tracking-wide text-neutral-400 block mb-1`}>Nest</label>
                    <select
                        css={tw`w-full bg-neutral-900 border border-neutral-600 rounded px-3 py-2 text-neutral-100 text-sm`}
                        value={config.nestId ?? ''}
                        onChange={(e) => {
                            const nestId = Number(e.target.value);
                            const egg = options.eggs.find((item) => item.nest_id === nestId);
                            setConfig((c) => ({ ...c, nestId, eggId: egg?.id ?? null }));
                        }}
                    >
                        {options.nests.map((nest) => (
                            <option key={nest.id} value={nest.id}>
                                {nest.name}
                            </option>
                        ))}
                    </select>
                </div>
                <div css={tw`mb-5`}>
                    <label css={tw`text-xs uppercase tracking-wide text-neutral-400 block mb-1`}>Egg / Game</label>
                    <select
                        css={tw`w-full bg-neutral-900 border border-neutral-600 rounded px-3 py-2 text-neutral-100 text-sm`}
                        value={config.eggId ?? ''}
                        onChange={(e) => setConfig((c) => ({ ...c, eggId: Number(e.target.value) }))}
                    >
                        {options.eggs
                            .filter((egg) => egg.nest_id === config.nestId)
                            .map((egg) => (
                                <option key={egg.id} value={egg.id}>
                                    {egg.name}
                                </option>
                            ))}
                    </select>
                </div>
                <div css={tw`grid grid-cols-1 sm:grid-cols-2 gap-5`}>
                    <FieldInput
                        label={`Memory (MB) — KSh ${priceOf('ram')} per GB`}
                        value={config.memory}
                        step={128}
                        onChange={(v) => updateField('memory', v)}
                    />
                    <FieldInput
                        label={`Disk (MB) — KSh ${priceOf('disk')} per GB`}
                        value={config.disk}
                        step={512}
                        onChange={(v) => updateField('disk', v)}
                    />
                    <FieldInput
                        label={`CPU (%) — KSh ${priceOf('cpu')} per 100%`}
                        value={config.cpu}
                        step={25}
                        onChange={(v) => updateField('cpu', v)}
                    />
                    <FieldInput
                        label={`Allocations — KSh ${priceOf('allocation')} per port`}
                        value={config.allocations}
                        step={1}
                        onChange={(v) => updateField('allocations', v)}
                    />
                    <FieldInput
                        label={`Databases — KSh ${priceOf('database')} each`}
                        value={config.databases}
                        step={1}
                        onChange={(v) => updateField('databases', v)}
                    />
                    <FieldInput
                        label={`Backups — KSh ${priceOf('backup')} each`}
                        value={config.backups}
                        step={1}
                        onChange={(v) => updateField('backups', v)}
                    />
                </div>
                <div css={tw`mt-6 pt-5 border-t border-neutral-700`}>
                    <div css={tw`flex justify-between items-baseline mb-2`}>
                        <p css={tw`text-sm text-neutral-400`}>Total Price</p>
                        <p css={tw`text-2xl font-bold text-neutral-100`}>
                            KSh {total.toFixed(2)}
                            {options.price_cap > 0 && (
                                <span css={tw`text-xs text-neutral-500 font-normal`}>
                                    {' '}
                                    / {options.price_cap.toFixed(2)} max
                                </span>
                            )}
                        </p>
                    </div>
                    {options.price_cap > 0 && (
                        <div css={tw`w-full h-2 bg-neutral-900 rounded-full overflow-hidden mb-5`}>
                            <div
                                css={[
                                    tw`h-full bg-cyan-500 transition-all duration-150`,
                                    { width: `${usagePercent}%` },
                                ]}
                            />
                        </div>
                    )}
                    <Button
                        onClick={() => setConfirmOpen(true)}
                        disabled={!config.nestId || !config.eggId || total <= 0}
                    >
                        Buy This Configuration
                    </Button>
                </div>
            </div>
            <Dialog.Confirm
                open={confirmOpen && !purchasing}
                onClose={() => setConfirmOpen(false)}
                title={'Confirm Purchase'}
                confirm={'Pay and Create'}
                onConfirmed={onPurchase}
            >
                <label css={tw`block text-sm text-neutral-300`}>
                    Server name
                    <input
                        value={serverName}
                        onChange={(e) => setServerName(e.target.value)}
                        placeholder={'Enter a name for your server'}
                        autoFocus
                        css={tw`mt-2 w-full bg-neutral-900 border border-neutral-600 rounded px-3 py-2 text-neutral-100`}
                    />
                </label>
                <label css={tw`block text-sm text-neutral-300 mt-4`}>
                    Kenyan M-Pesa phone (required for Kenyan accounts)
                    <input
                        value={phone}
                        onChange={(e) => setPhone(e.target.value)}
                        placeholder={'0712345678'}
                        css={tw`mt-2 w-full bg-neutral-900 border border-neutral-600 rounded px-3 py-2 text-neutral-100`}
                    />
                </label>
                <p css={tw`text-sm text-neutral-300 mt-4`}>
                    Your country determines the gateway. The server description will be “CREATED BY COURTNEY”.
                </p>
            </Dialog.Confirm>
            {purchasing && (
                <Dialog open title={'Processing payment'} onClose={() => undefined}>
                    <div css={tw`flex items-center gap-4`}>
                        <Spinner />
                        <p css={tw`text-sm text-neutral-300`}>
                            Complete the payment and wait for server provisioning...
                        </p>
                    </div>
                </Dialog>
            )}
            <Dialog open={!!error} onClose={() => setError(null)} title={'Purchase Failed'}>
                <p css={tw`text-sm text-neutral-300`}>{error}</p>
                <Dialog.Footer>
                    <Button onClick={() => setError(null)}>Close</Button>
                </Dialog.Footer>
            </Dialog>
        </div>
    );
};
const FieldInput = ({
    label,
    value,
    step,
    onChange,
}: {
    label: string;
    value: number;
    step: number;
    onChange: (value: number) => void;
}) => (
    <div>
        <label css={tw`text-xs uppercase tracking-wide text-neutral-400 block mb-1`}>{label}</label>
        <input
            type={'number'}
            min={0}
            step={step}
            value={value}
            onChange={(e) => onChange(Number(e.target.value) || 0)}
            css={tw`w-full bg-neutral-900 border border-neutral-600 rounded px-3 py-2 text-neutral-100 text-sm`}
        />
    </div>
);
