import React, { useEffect, useMemo, useState } from 'react';
import { useHistory } from 'react-router-dom';
import Spinner from '@/components/elements/Spinner';
import Button from '@/components/elements/Button';
import { Dialog } from '@/components/elements/dialog';
import http from '@/api/http';
import tw from 'twin.macro';
import { useFlashKey } from '@/plugins/useFlash';
import FlashMessageRender from '@/components/FlashMessageRender';

interface Egg { id: number; name: string; }
interface Options {
    eggs: Record<string, Egg[]>;
    ram_options: number[];
    ram_prices: { min: number; max: number | null; price_kes: number }[];
}

export default () => {
    const history = useHistory();
    const [options, setOptions] = useState<Options | null>(null);
    const [ramGb, setRamGb] = useState(1);
    const [nestName, setNestName] = useState('');
    const [eggId, setEggId] = useState<number | null>(null);
    const [serverName, setServerName] = useState('');
    const [nameOpen, setNameOpen] = useState(false);
    const [purchasing, setPurchasing] = useState(false);
    const [errorDialog, setErrorDialog] = useState<string | null>(null);
    const { clearFlashes, clearAndAddHttpError } = useFlashKey('account:custom-build');

    useEffect(() => {
        clearFlashes();
        http.get('/account/store/custom/options')
            .then((response) => {
                const data = response.data as Options;
                const firstNest = Object.keys(data.eggs)[0] || '';
                setOptions(data);
                setNestName(firstNest);
                setRamGb(data.ram_options?.[0] || 1);
                setEggId(data.eggs[firstNest]?.[0]?.id || null);
            })
            .catch((error) => clearAndAddHttpError(error));
    }, []);

    const eggs = options?.eggs[nestName] || [];
    const price = useMemo(() => {
        if (!options) return 0;
        const tier = options.ram_prices.find((item) => ramGb >= item.min && (item.max === null || ramGb <= item.max));
        return tier?.price_kes || 0;
    }, [options, ramGb]);

    const onPurchase = () => {
        if (!eggId || !serverName.trim() || !price) return;
        setPurchasing(true);
        http.post('/account/store/custom/purchase', { egg_id: eggId, ram_gb: ramGb, server_name: serverName.trim() })
            .then((response) => history.push(`/server/${response.data.server_id}`))
            .catch((error) => {
                setPurchasing(false);
                setNameOpen(false);
                const message = error?.response?.data?.error;
                if (message) setErrorDialog(message);
                else clearAndAddHttpError(error);
            });
    };

    if (!options) return <Spinner centered size={'large'} />;

    return (
        <div>
            <FlashMessageRender byKey={'account:custom-build'} css={tw`mb-4`} />
            <div css={tw`bg-neutral-800 border border-neutral-700 rounded-lg p-6`}>
                <div css={tw`mb-5`}>
                    <label css={tw`text-xs uppercase tracking-wide text-neutral-400 block mb-1`}>RAM</label>
                    <select css={tw`w-full bg-neutral-900 border border-neutral-600 rounded px-3 py-2 text-neutral-100 text-sm`} value={ramGb} onChange={(e) => setRamGb(Number(e.target.value))}>
                        {options.ram_options.map((gb) => <option key={gb} value={gb}>{gb} GB RAM — KSh {options.ram_prices.find((tier) => gb >= tier.min && (tier.max === null || gb <= tier.max))?.price_kes ?? 0}</option>)}
                    </select>
                </div>
                <div css={tw`mb-5`}>
                    <label css={tw`text-xs uppercase tracking-wide text-neutral-400 block mb-1`}>Nest</label>
                    <select css={tw`w-full bg-neutral-900 border border-neutral-600 rounded px-3 py-2 text-neutral-100 text-sm`} value={nestName} onChange={(e) => { const next = e.target.value; setNestName(next); setEggId(options.eggs[next]?.[0]?.id || null); }}>
                        {Object.keys(options.eggs).map((name) => <option key={name} value={name}>{name}</option>)}
                    </select>
                </div>
                <div css={tw`mb-5`}>
                    <label css={tw`text-xs uppercase tracking-wide text-neutral-400 block mb-1`}>Egg / Game</label>
                    <select css={tw`w-full bg-neutral-900 border border-neutral-600 rounded px-3 py-2 text-neutral-100 text-sm`} value={eggId ?? ''} onChange={(e) => setEggId(Number(e.target.value))}>
                        {eggs.map((egg) => <option key={egg.id} value={egg.id}>{egg.name}</option>)}
                    </select>
                </div>
                <div css={tw`rounded bg-neutral-900 border border-neutral-700 p-4 text-sm text-neutral-300 mb-5`}>
                    <p>Disk, CPU, databases, backups, and allocation are filled automatically.</p>
                    <p css={tw`mt-2 text-neutral-400`}>Price: <strong css={tw`text-neutral-100`}>KSh {price.toFixed(2)}</strong></p>
                </div>
                <Button onClick={() => setNameOpen(true)} disabled={!eggId || !price}>Continue</Button>
            </div>
            <Dialog open={nameOpen && !purchasing} title={'Name your server'} onClose={() => setNameOpen(false)}>
                <label css={tw`text-xs uppercase tracking-wide text-neutral-400 block mb-1`}>Server name</label>
                <input autoFocus value={serverName} onChange={(e) => setServerName(e.target.value)} placeholder={'My Game Server'} maxLength={191} css={tw`w-full bg-neutral-900 border border-neutral-600 rounded px-3 py-2 text-neutral-100 text-sm`} />
                <div css={tw`flex justify-end gap-3 mt-5`}>
                    <button onClick={() => setNameOpen(false)} css={tw`text-sm text-neutral-400 hover:text-neutral-200`}>Cancel</button>
                    <Button onClick={onPurchase} disabled={!serverName.trim()}>Create Server</Button>
                </div>
            </Dialog>
            {purchasing && <Dialog open title={'Provisioning your server'} onClose={() => undefined}><div css={tw`flex items-center gap-4`}><Spinner /><p css={tw`text-sm text-neutral-300`}>Please wait, this can take a few moments...</p></div></Dialog>}
            <Dialog open={!!errorDialog} onClose={() => setErrorDialog(null)} title={'Purchase Failed'}><p css={tw`text-sm text-neutral-300`}>{errorDialog}</p><Dialog.Footer><button onClick={() => setErrorDialog(null)} css={tw`text-sm text-neutral-400 hover:text-neutral-200`}>Close</button></Dialog.Footer></Dialog>
        </div>
    );
};
