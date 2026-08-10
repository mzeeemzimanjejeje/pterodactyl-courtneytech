import React from 'react';
import { ServerContext } from '@/state/server';
import ScreenBlock from '@/components/elements/ScreenBlock';
import ServerInstallSvg from '@/assets/images/server_installing.svg';
import ServerErrorSvg from '@/assets/images/server_error.svg';
import ServerRestoreSvg from '@/assets/images/server_restore.svg';
import ServerContentBlock from '@/components/elements/ServerContentBlock';
import Console from '@/components/server/console/Console';

export default () => {
    const status = ServerContext.useStoreState((state) => state.server.data?.status || null);
    const isTransferring = ServerContext.useStoreState((state) => state.server.data?.isTransferring || false);
    const isNodeUnderMaintenance = ServerContext.useStoreState(
        (state) => state.server.data?.isNodeUnderMaintenance || false
    );

    if (status === 'installing') {
        return (
            <ServerContentBlock title={'Installation Console'}>
                <div className={'mb-4 rounded bg-yellow-500/10 p-3 text-sm text-yellow-200'}>
                    Installation is in progress. Live installer output will appear below. Please keep this page open or
                    refresh it to reconnect.
                </div>
                <Console />
            </ServerContentBlock>
        );
    }

    return status === 'install_failed' || status === 'reinstall_failed' ? (
        <ScreenBlock
            title={'Installation Failed'}
            image={ServerInstallSvg}
            message={'The installation failed. Check the installation console or retry the installation.'}
        />
    ) : status === 'suspended' ? (
        <ScreenBlock
            title={'Server Suspended'}
            image={ServerErrorSvg}
            message={'This server is suspended and cannot be accessed.'}
        />
    ) : isNodeUnderMaintenance ? (
        <ScreenBlock
            title={'Node under Maintenance'}
            image={ServerErrorSvg}
            message={'The node of this server is currently under maintenance.'}
        />
    ) : (
        <ScreenBlock
            title={isTransferring ? 'Transferring' : 'Restoring from Backup'}
            image={ServerRestoreSvg}
            message={
                isTransferring
                    ? 'Your server is being transferred to a new node, please check back later.'
                    : 'Your server is currently being restored from a backup, please check back in a few minutes.'
            }
        />
    );
};
