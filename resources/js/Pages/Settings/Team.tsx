import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import TextInput from '@/Components/TextInput';
import AppLayout from '@/Components/layouts/AppLayout';
import { PageHeader } from '@/Components/shared/PageHeader';
import { Head, useForm, router } from '@inertiajs/react';
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
    token: string;
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
    owner:  'bg-indigo-100 text-indigo-700',
    admin:  'bg-zinc-100 text-zinc-700',
    member: 'bg-zinc-50 text-zinc-500',
};

const formatDate = formatDateOnly;

export default function Team({
    workspace,
    members,
    invitations,
    userRole,
    authUser,
}: {
    workspace: WorkspaceInfo;
    members: Member[];
    invitations: Invitation[];
    userRole: string;
    authUser: { id: number };
}) {
    const canManage = userRole === 'owner' || userRole === 'admin';
    const isOwner   = userRole === 'owner';

    const inviteForm = useForm({ email: '', role: 'member' as string });
    const [transferUserId, setTransferUserId] = useState<number | null>(null);

    const submitInvite: FormEventHandler = (e) => {
        e.preventDefault();
        inviteForm.post(route('settings.team.invite'), {
            onSuccess: () => inviteForm.reset(),
        });
    };

    const revokeInvitation = (token: string) => {
        router.delete(route('settings.team.invitations.destroy', token));
    };

    const removeMember = (workspaceUserId: number) => {
        if (!confirm('Remove this member from the workspace?')) return;
        router.delete(route('settings.team.members.destroy', workspaceUserId));
    };

    const updateRole = (workspaceUserId: number, role: string) => {
        router.patch(route('settings.team.members.update', workspaceUserId), { role });
    };

    const transferOwnership = () => {
        if (!transferUserId) return;
        if (!confirm('Transfer ownership? You will become an admin.')) return;
        router.post(route('settings.team.transfer'), { user_id: transferUserId });
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
                                    <InputLabel htmlFor="invite_email" value="Email address" />
                                    <TextInput
                                        id="invite_email"
                                        type="email"
                                        value={inviteForm.data.email}
                                        onChange={(e) => inviteForm.setData('email', e.target.value)}
                                        className="mt-1 block w-full"
                                        placeholder="colleague@example.com"
                                        required
                                    />
                                    <InputError message={inviteForm.errors.email} className="mt-2" />
                                </div>
                                <div className="w-36">
                                    <InputLabel htmlFor="invite_role" value="Role" />
                                    <select
                                        id="invite_role"
                                        value={inviteForm.data.role}
                                        onChange={(e) => inviteForm.setData('role', e.target.value)}
                                        className="mt-1 block w-full rounded-md border-zinc-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                    >
                                        <option value="member">Member</option>
                                        <option value="admin">Admin</option>
                                    </select>
                                    <InputError message={inviteForm.errors.role} className="mt-2" />
                                </div>
                            </div>
                            <button
                                type="submit"
                                disabled={inviteForm.processing}
                                className="mt-4 rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50 transition-colors"
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
                                        onClick={() => revokeInvitation(inv.token)}
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
                                                className="rounded border border-zinc-300 py-1 text-xs focus:border-indigo-500 focus:ring-indigo-500"
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
                                    className="flex-1 rounded-md border border-zinc-300 py-1.5 text-sm focus:border-indigo-500 focus:ring-indigo-500"
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
