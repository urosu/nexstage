import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import AppLayout from '@/Components/layouts/AppLayout';
import { PageHeader } from '@/Components/shared/PageHeader';
import { Head, useForm, router, usePage } from '@inertiajs/react';
import { wurl } from '@/lib/workspace-url';
import type { PageProps } from '@/types';
import { FormEventHandler, useState } from 'react';
import { formatDateOnly } from '@/lib/formatters';

interface Member {
    id: number;
    user_id: number;
    name: string;
    email: string;
    role: string;
    joined_at: string;
    last_login: string | null;
}

interface Invitation {
    id: number;
    email: string;
    role: string;
    expires_at: string;
}

interface WorkspaceInfo {
    id: number;
    name: string;
    owner_id: number;
}

const ROLE_LABELS: Record<string, string> = {
    owner:  'Owner',
    admin:  'Admin',
    member: 'Member',
};

const ROLE_BADGE: Record<string, string> = {
    owner:  'bg-primary/15 text-primary',
    admin:  'bg-zinc-100 text-zinc-700',
    member: 'bg-zinc-50 text-zinc-500',
};

const formatDate = formatDateOnly;

export default function Team({
    workspaceInfo,
    members,
    invitations,
    userRole,
    authUser,
}: {
    workspaceInfo: WorkspaceInfo;
    members: Member[];
    invitations: Invitation[];
    userRole: string;
    authUser: { id: number };
}) {
    const canManage = userRole === 'owner' || userRole === 'admin';
    const isOwner   = userRole === 'owner';
    const { workspace: ws } = usePage<PageProps>().props;
    const w = (path: string) => wurl(ws?.slug, path);

    const inviteForm = useForm({ email: '', role: 'member' as string });
    const [transferUserId, setTransferUserId] = useState<number | null>(null);

    const submitInvite: FormEventHandler = (e) => {
        e.preventDefault();
        inviteForm.post(w('/settings/team/invite'), {
            onSuccess: () => inviteForm.reset(),
        });
    };

    const revokeInvitation = (id: number) => {
        router.delete(w(`/settings/team/invitations/${id}`));
    };

    const removeMember = (workspaceUserId: number) => {
        if (!confirm('Remove this member from the workspace?')) return;
        router.delete(w(`/settings/team/members/${workspaceUserId}`));
    };

    const updateRole = (workspaceUserId: number, role: string) => {
        router.patch(w(`/settings/team/members/${workspaceUserId}`), { role });
    };

    const transferOwnership = () => {
        if (!transferUserId) return;
        if (!confirm('Transfer ownership? You will become an admin.')) return;
        router.post(w('/settings/team/transfer'), { user_id: transferUserId });
    };

    const nonOwnerMembers = members.filter((m) => m.role !== 'owner');

    return (
        <AppLayout>
            <Head title="Team" />

            <PageHeader
                title="Team"
                subtitle={`${members.length} member${members.length !== 1 ? 's' : ''} in this workspace`}
            />

            <div className="mt-6 max-w-2xl space-y-6">

                {/* Invite member */}
                {canManage && (
                    <div className="overflow-hidden rounded-lg border border-zinc-200 bg-white">
                        <div className="border-b border-zinc-200 px-6 py-4">
                            <h3 className="text-base font-semibold text-zinc-900">Invite member</h3>
                        </div>
                        <form onSubmit={submitInvite} className="px-6 py-5">
                            <div className="flex gap-3">
                                <div className="flex-1">
                                    <Label htmlFor="invite_email">Email address</Label>
                                    <Input
                                        id="invite_email"
                                        type="email"
                                        value={inviteForm.data.email}
                                        onChange={(e) => inviteForm.setData('email', e.target.value)}
                                        className="mt-1"
                                        placeholder="colleague@example.com"
                                        required
                                    />
                                    {inviteForm.errors.email && <p className="mt-2 text-sm text-red-600">{inviteForm.errors.email}</p>}
                                </div>
                                <div className="w-36">
                                    <Label htmlFor="invite_role">Role</Label>
                                    <select
                                        id="invite_role"
                                        value={inviteForm.data.role}
                                        onChange={(e) => inviteForm.setData('role', e.target.value)}
                                        className="mt-1 block w-full rounded-md border-zinc-300 shadow-sm focus:border-primary focus:ring-primary"
                                    >
                                        <option value="member">Member</option>
                                        <option value="admin">Admin</option>
                                    </select>
                                    {inviteForm.errors.role && <p className="mt-2 text-sm text-red-600">{inviteForm.errors.role}</p>}
                                </div>
                            </div>
                            <button
                                type="submit"
                                disabled={inviteForm.processing}
                                className="mt-4 rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-50 transition-colors"
                            >
                                Send invitation
                            </button>
                        </form>
                    </div>
                )}

                {/* Pending invitations */}
                {canManage && invitations.length > 0 && (
                    <div className="overflow-hidden rounded-lg border border-zinc-200 bg-white">
                        <div className="border-b border-zinc-200 px-6 py-4">
                            <h3 className="text-base font-semibold text-zinc-900">
                                Pending invitations ({invitations.length})
                            </h3>
                        </div>
                        <ul className="divide-y divide-zinc-100">
                            {invitations.map((inv) => (
                                <li key={inv.id} className="flex items-center justify-between px-6 py-4">
                                    <div>
                                        <p className="text-sm font-medium text-zinc-900">{inv.email}</p>
                                        <p className="text-xs text-zinc-400">
                                            {ROLE_LABELS[inv.role]} · expires {formatDate(inv.expires_at)}
                                        </p>
                                    </div>
                                    <button
                                        type="button"
                                        onClick={() => revokeInvitation(inv.id)}
                                        className="text-xs text-red-600 hover:text-red-800 transition-colors"
                                    >
                                        Revoke
                                    </button>
                                </li>
                            ))}
                        </ul>
                    </div>
                )}

                {/* Members list */}
                <div className="overflow-hidden rounded-lg border border-zinc-200 bg-white">
                    <div className="border-b border-zinc-200 px-6 py-4">
                        <h3 className="text-base font-semibold text-zinc-900">Members</h3>
                    </div>
                    <ul className="divide-y divide-zinc-100">
                        {members.map((member) => {
                            const isSelf      = member.user_id === authUser.id;
                            const isThisOwner = member.role === 'owner';

                            return (
                                <li key={member.id} className="flex items-center justify-between px-6 py-4">
                                    <div className="flex min-w-0 items-center gap-3">
                                        <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-zinc-200 text-sm font-medium text-zinc-700">
                                            {member.name.charAt(0).toUpperCase()}
                                        </div>
                                        <div className="min-w-0">
                                            <p className="text-sm font-medium text-zinc-900">
                                                {member.name}
                                                {isSelf && (
                                                    <span className="ml-2 text-xs text-zinc-400">(you)</span>
                                                )}
                                            </p>
                                            <p className="truncate text-xs text-zinc-500">{member.email}</p>
                                        </div>
                                        <span className={`shrink-0 rounded-full px-2 py-0.5 text-xs font-medium ${ROLE_BADGE[member.role]}`}>
                                            {ROLE_LABELS[member.role]}
                                        </span>
                                    </div>

                                    {canManage && !isSelf && !isThisOwner && (
                                        <div className="ml-3 flex shrink-0 items-center gap-3">
                                            <select
                                                value={member.role}
                                                onChange={(e) => updateRole(member.id, e.target.value)}
                                                className="rounded border border-zinc-300 py-1 text-xs focus:border-primary focus:ring-primary"
                                            >
                                                <option value="member">Member</option>
                                                <option value="admin">Admin</option>
                                            </select>
                                            <button
                                                type="button"
                                                onClick={() => removeMember(member.id)}
                                                className="text-xs text-red-600 hover:text-red-800 transition-colors"
                                            >
                                                Remove
                                            </button>
                                        </div>
                                    )}
                                </li>
                            );
                        })}
                    </ul>
                </div>

                {/* Transfer ownership — owner only */}
                {isOwner && nonOwnerMembers.length > 0 && (
                    <div className="overflow-hidden rounded-lg border border-zinc-200 bg-white">
                        <div className="border-b border-zinc-200 px-6 py-4">
                            <h3 className="text-base font-semibold text-zinc-900">Transfer ownership</h3>
                        </div>
                        <div className="px-6 py-5">
                            <p className="text-sm text-zinc-600">
                                Transfer ownership to another member. You will become an admin.
                            </p>
                            <div className="mt-4 flex gap-3">
                                <select
                                    value={transferUserId ?? ''}
                                    onChange={(e) =>
                                        setTransferUserId(e.target.value ? parseInt(e.target.value) : null)
                                    }
                                    className="flex-1 rounded-md border border-zinc-300 py-1.5 text-sm focus:border-primary focus:ring-primary"
                                >
                                    <option value="">Select a member…</option>
                                    {nonOwnerMembers.map((m) => (
                                        <option key={m.user_id} value={m.user_id}>
                                            {m.name} ({m.email})
                                        </option>
                                    ))}
                                </select>
                                <button
                                    type="button"
                                    onClick={transferOwnership}
                                    disabled={!transferUserId}
                                    className="rounded-md border border-red-300 px-4 py-2 text-sm font-medium text-red-700 hover:bg-red-50 disabled:opacity-50 transition-colors"
                                >
                                    Transfer
                                </button>
                            </div>
                        </div>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
