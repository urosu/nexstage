import { usePage } from '@inertiajs/react';
import { PageProps, Workspace } from '@/types';

export function useWorkspace(): {
    workspace: Workspace | undefined;
    workspaces: Workspace[] | undefined;
} {
    const { workspace, workspaces } = usePage<PageProps>().props;
    return { workspace, workspaces };
}
