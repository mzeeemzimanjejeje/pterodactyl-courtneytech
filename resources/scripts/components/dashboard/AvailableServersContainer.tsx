import React, { useEffect, useRef, useState } from 'react';
import { useHistory } from 'react-router-dom';
import PageContentBlock from '@/components/elements/PageContentBlock';
import Spinner from '@/components/elements/Spinner';
import Button from '@/components/elements/Button';
import Input from '@/components/elements/Input';
import { Dialog } from '@/components/elements/dialog';
import http from '@/api/http';
import tw from 'twin.macro';
import { useFlashKey } from '@/plugins/useFlash';
import FlashMessageRender from '@/components/FlashMessageRender';
import CustomBuildContainer from '@/components/dashboard/CustomBuildContainer';

interface Plan {
    id: number;
    name: string;
    description: string | null;
    price: string;
    currency: string;
    billing_period: string;
    memory: number;
    disk: number;
    cpu: number;
    databases: number;
    backups: number;
    allocations: number;
    egg_id: number;
    nest_id: number | null;
    is_featured: boolean;
}

declare global {
    interface Window {
        PaystackPop?: { setup: (options: Record<string, any>) => { openIframe: () => void } };
    }
}

const PAYSTACK_SCRIPT_ID = 'paystack-inline-js';
const loadPaystackScript = (): Promise<void> =>
    new Promise((resolve, reject) => {
        if (window.PaystackPop) return resolve();
        const existing = document.getElementById(PAYSTACK_SCRIPT_ID) as HTMLScriptElement | null;
        if (existing) {
            existing.addEventListener('load', () => resolve());
            existing.addEventListener('error', () => reject(new Error('Failed to load Paystack.')));
            return;
        }
        const script = document.createElement('script');
        script.id = PAYSTACK_SCRIPT_ID;
        script.src = 'https://js.paystack.co/v1/inline.js';
        script.async = true;
        script.onload = () => resolve();
        script.onerror = () => reject(new Error('Failed to load Paystack.'));
        document.head.appendChild(script);
    });

export default () => {
    const history = useHistory();
    const [plans, setPlans] = useState<Plan[] | null>(null);
    const [selected, setSelected] = useState<Plan | null>(null);
    const [serverName, setServerName] = useState('');
    const [phone, setPhone] = useState('');
    const [purchasing, setPurchasing] = useState(false);
    const [errorDialog, setErrorDialog] = useState<{ title: string; message: string } | null>(null);
    const [tab, setTab] = useState<'plans' | 'custom'>('plans');
    const { clearFlashes, clearAndAddHttpError } = useFlashKey('account:store');
    const timer = useRef<ReturnType<typeof setTimeout> | null>(null);

    useEffect(() => {
        clearFlashes();
        http.get('/account/store/plans')
            .then((response) => setPlans(response.data))
            .catch(clearAndAddHttpError);
        return () => {
            if (timer.current) clearTimeout(timer.current);
        };
    }, []);

    const poll = (reference: string, attempt = 0): Promise<any> =>
        new Promise((resolve) => {
            http.get(`/account/store/payment/status/${reference}`)
                .then(({ data }) => {
                    if (
                        data.status === 'success' ||
                        data.status === 'failed' ||
                        data.status === 'confirmed_provisioning_failed' ||
                        attempt >= 40
                    )
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
        if (data.status === 'success' && data.server_id) {
            history.push(`/server/${data.server_id}`);
        } else {
            setErrorDialog({
                title: 'Payment Failed',
                message:
                    data.status === 'confirmed_provisioning_failed'
                        ? 'Payment was confirmed, but the server could not be provisioned. Please contact support.'
                        : 'We could not confirm this payment.',
            });
        }
    };

    const onConfirmPurchase = async () => {
        if (!selected || !serverName.trim()) return;
        setPurchasing(true);
        clearFlashes();
        try {
            const { data } = await http.post('/account/store/payment/initialize', {
                server_name: serverName.trim(),
                plan_id: selected.id,
                nest_id: selected.nest_id,
                egg_id: selected.egg_id,
                memory: selected.memory,
                disk: selected.disk,
                cpu: selected.cpu,
                databases: selected.databases,
                backups: selected.backups,
                allocations: selected.allocations,
                phone: phone.trim() || undefined,
            });
            if (data.gateway === 'courtneytech') {
                const result = await poll(data.reference);
                finish(result);
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
        } catch (error: any) {
            setPurchasing(false);
            setErrorDialog({
                title: 'Purchase Failed',
                message: error?.response?.data?.error || error.message || 'Unable to initialize payment.',
            });
        }
    };

    return (
        <PageContentBlock title={'Available Servers'}>
            <FlashMessageRender byKey={'account:store'} css={tw`mb-4`} />
            <div css={tw`flex gap-2 mb-6`}>
                {(['plans', 'custom'] as const).map((item) => (
                    <button
                        key={item}
                        onClick={() => setTab(item)}
                        css={[
                            tw`px-4 py-2 rounded-lg text-sm font-medium border`,
                            tab === item
                                ? tw`bg-cyan-600 border-cyan-600 text-white`
                                : tw`bg-neutral-900 border-neutral-600 text-neutral-300 hover:border-neutral-400`,
                        ]}
                    >
                        {item === 'plans' ? 'Fixed Plans' : 'Build Your Own'}
                    </button>
                ))}
            </div>
            {tab === 'custom' && <CustomBuildContainer />}
            {tab === 'plans' &&
                (!plans ? (
                    <Spinner centered size={'large'} />
                ) : plans.length === 0 ? (
                    <p css={tw`text-sm text-neutral-400`}>No plans are available for purchase right now.</p>
                ) : (
                    <div css={tw`grid grid-cols-1 md:grid-cols-3 lg:grid-cols-4 gap-4`}>
                        {plans.map((plan) => (
                            <div
                                key={plan.id}
                                css={[
                                    tw`bg-neutral-800 border rounded-lg p-5 flex flex-col relative`,
                                    plan.is_featured ? tw`border-cyan-500` : tw`border-neutral-700`,
                                ]}
                            >
                                {plan.is_featured && (
                                    <span
                                        css={tw`absolute -top-2 left-1/2 transform -translate-x-1/2 bg-cyan-600 text-white text-xs font-bold px-2 py-0.5 rounded-full`}
                                    >
                                        POPULAR
                                    </span>
                                )}
                                <p css={tw`text-neutral-100 font-bold mb-1`}>{plan.name}</p>
                                <p css={tw`text-xs text-neutral-400 mb-3 min-h-[16px]`}>{plan.description}</p>
                                <p css={tw`text-xl font-bold text-neutral-100 mb-1`}>
                                    {plan.currency} {parseFloat(plan.price).toFixed(2)}
                                    <span css={tw`text-xs text-neutral-400 font-normal block`}>
                                        / {plan.billing_period}
                                    </span>
                                </p>
                                <ul css={tw`text-xs text-neutral-400 border-t border-neutral-700 mt-3 pt-3 mb-4`}>
                                    <li css={tw`flex justify-between py-0.5`}>
                                        <span>Memory</span>
                                        <span css={tw`text-neutral-200`}>{plan.memory} MB</span>
                                    </li>
                                    <li css={tw`flex justify-between py-0.5`}>
                                        <span>Disk</span>
                                        <span css={tw`text-neutral-200`}>{plan.disk} MB</span>
                                    </li>
                                    <li css={tw`flex justify-between py-0.5`}>
                                        <span>CPU</span>
                                        <span css={tw`text-neutral-200`}>{plan.cpu}%</span>
                                    </li>
                                    <li css={tw`flex justify-between py-0.5`}>
                                        <span>Databases</span>
                                        <span css={tw`text-neutral-200`}>{plan.databases}</span>
                                    </li>
                                    <li css={tw`flex justify-between py-0.5`}>
                                        <span>Backups</span>
                                        <span css={tw`text-neutral-200`}>{plan.backups}</span>
                                    </li>
                                </ul>
                                <Button
                                    css={tw`mt-auto`}
                                    onClick={() => {
                                        setServerName('');
                                        setPhone('');
                                        setSelected(plan);
                                    }}
                                >
                                    Buy Now
                                </Button>
                            </div>
                        ))}
                    </div>
                ))}
            <Dialog.Confirm
                open={!!selected && !purchasing}
                onClose={() => setSelected(null)}
                title={'Confirm Purchase'}
                confirm={'Pay and Create'}
                onConfirmed={onConfirmPurchase}
            >
                {selected && (
                    <>
                        <label css={tw`block text-sm text-neutral-300`}>
                            Server name
                            <Input
                                name={'server_name'}
                                value={serverName}
                                onChange={(event) => setServerName(event.target.value)}
                                placeholder={'Enter a name for your server'}
                                autoFocus
                                required
                                css={tw`mt-2`}
                            />
                        </label>
                        <label css={tw`block text-sm text-neutral-300 mt-4`}>
                            Kenyan M-Pesa phone (required for Kenyan accounts)
                            <Input
                                name={'phone'}
                                value={phone}
                                onChange={(event) => setPhone(event.target.value)}
                                placeholder={'0712345678'}
                                css={tw`mt-2`}
                            />
                        </label>
                        <p css={tw`text-sm text-neutral-300 mt-4`}>
                            Your country determines the payment gateway. After successful confirmation, the server is
                            provisioned with the description “CREATED BY COURTNEY”.
                        </p>
                    </>
                )}
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
            <Dialog open={!!errorDialog} onClose={() => setErrorDialog(null)} title={errorDialog?.title || 'Error'}>
                <p css={tw`text-sm text-neutral-300`}>{errorDialog?.message}</p>
                <Dialog.Footer>
                    <Button onClick={() => setErrorDialog(null)}>Close</Button>
                </Dialog.Footer>
            </Dialog>
        </PageContentBlock>
    );
};
