import { Link, router, usePage } from '@inertiajs/react';
import { Toaster } from '@/components/ui/sonner';
import { toast } from 'sonner';
import { AlertBanner } from '@/Components/shared/AlertBanner';
import {
    LayoutDashboard,
    Store,
    BarChart2,
    Lightbulb,
    Settings,
    Bell,
    ChevronDown,
    Menu,
    X,
    LogOut,
    User,
    CreditCard,
    Users,
    Puzzle,
    Check,
    ShieldCheck,
    Search,
    ChevronRight,
    Clipboard,
    Building2,
    ScrollText,
    Bug,
    FileCode2,
} from 'lucide-react';
import { PropsWithChildren, ReactNode, useEffect, useState } from 'react';
import { cn } from '@/lib/utils';
import { PageProps, Store as StoreType, Workspace } from '@/types';

// ─── Nav config types ─────────────────────────────────────────────────────────

interface FlatNavItem {
    label: string;
    href: string;
    icon?: React.ComponentType<{ className?: string }>;
    /** Show hollow dot indicator when true (e.g. integration not connected) */
    indicator?: boolean;
    /** Use exact path match for active detection (no prefix matching) */
    exact?: boolean;
}

interface NavGroup {
    type: 'group';
    key: string;
    label: string;
    icon: React.ComponentType<{ className?: string }>;
    /** Paths that cause this group to be considered active / auto-expand */
    basePaths: string[];
    children: FlatNavItem[];
}

interface NavFlat extends FlatNavItem {
    type: 'flat';
}

type NavEntry = NavGroup | NavFlat;

// ─── Helpers ──────────────────────────────────────────────────────────────────

function matchesPath(pathname: string, paths: string[]): boolean {
    return paths.some((p) => pathname === p || pathname.startsWith(p + '/'));
}

function isActiveFlat(href: string, pathname: string, exact = false): boolean {
    if (exact) return pathname === href;
    return pathname === href || pathname.startsWith(href + '/');
}

// ─── Sub-components ───────────────────────────────────────────────────────────

function SidebarLink({
    item,
    indent = false,
    onClick,
}: {
    item: FlatNavItem;
    indent?: boolean;
    onClick?: () => void;
}) {
    const pathname = typeof window !== 'undefined' ? window.location.pathname : '';
    const active   = isActiveFlat(item.href, pathname, item.exact);
    const Icon     = item.icon;

    return (
        <Link
            href={item.href}
            onClick={onClick}
            className={cn(
                'group flex items-center gap-2.5 rounded-lg px-3 py-1.5 text-sm font-medium transition-colors',
                indent ? 'pl-8' : '',
                active
                    ? 'bg-indigo-50 text-indigo-600'
                    : 'text-zinc-600 hover:bg-zinc-100 hover:text-zinc-900',
            )}
        >
            {Icon && (
                <Icon className={cn('h-4 w-4 shrink-0', active ? 'text-indigo-600' : 'text-zinc-400 group-hover:text-zinc-600')} />
            )}
            <span className="flex-1 truncate">{item.label}</span>
            {item.indicator && (
                <span className="h-1.5 w-1.5 rounded-full border border-zinc-300" title="Not connected" />
            )}
        </Link>
    );
}

function SidebarGroupItem({
    group,
    isOpen,
    onToggle,
    onClick,
}: {
    group: NavGroup;
    isOpen: boolean;
    onToggle: () => void;
    onClick?: () => void;
}) {
    const pathname = typeof window !== 'undefined' ? window.location.pathname : '';
    const active   = matchesPath(pathname, group.basePaths);
    const Icon     = group.icon;

    function handleHeaderClick(): void {
        // Navigate to first child when opening; just toggle when already open
        if (!isOpen && group.children.length > 0) {
            router.get(group.children[0].href);
            onClick?.();
        }
        onToggle();
    }

    return (
        <div>
            {/* Group header — click to navigate to first child + toggle */}
            <button
                onClick={handleHeaderClick}
                className={cn(
                    'flex w-full items-center gap-2.5 rounded-lg px-3 py-1.5 text-sm font-medium transition-colors',
                    active
                        ? 'text-indigo-600'
                        : 'text-zinc-600 hover:bg-zinc-100 hover:text-zinc-900',
                )}
            >
                <Icon className={cn('h-4 w-4 shrink-0', active ? 'text-indigo-600' : 'text-zinc-400')} />
                <span className="flex-1 text-left">{group.label}</span>
                <ChevronRight
                    className={cn(
                        'h-3.5 w-3.5 shrink-0 transition-transform',
                        active ? 'text-indigo-400' : 'text-zinc-300',
                        isOpen && 'rotate-90',
                    )}
                />
            </button>

            {/* Children */}
            {isOpen && (
                <div className="mt-0.5 space-y-0.5">
                    {group.children.map((child) => (
                        <SidebarLink key={child.href} item={child} indent onClick={onClick} />
                    ))}
                </div>
            )}
        </div>
    );
}

// ─── Workspace switcher ───────────────────────────────────────────────────────

function WorkspaceSwitcher({ workspace, workspaces }: { workspace: Workspace | undefined; workspaces: Workspace[] | undefined }) {
    const [open, setOpen] = useState(false);

    if (!workspace) return null;

    return (
        <div className="relative">
            <button
                onClick={() => setOpen((v) => !v)}
                className="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-100 transition-colors"
            >
                <div className="flex h-6 w-6 shrink-0 items-center justify-center rounded bg-indigo-600 text-xs font-bold text-white">
                    {workspace.name.charAt(0).toUpperCase()}
                </div>
                <span className="flex-1 truncate text-left">{workspace.name}</span>
                <ChevronDown className={cn('h-3.5 w-3.5 text-zinc-400 transition-transform', open && 'rotate-180')} />
            </button>

            {open && (
                <>
                    <div className="fixed inset-0 z-10" onClick={() => setOpen(false)} />
                    <div className="absolute bottom-full left-0 z-20 mb-1 w-full rounded-lg border border-zinc-200 bg-white py-1 shadow-lg">
                        <div className="px-3 py-1.5 text-xs font-medium text-zinc-400 uppercase tracking-wide">
                            Workspaces
                        </div>
                        {workspaces?.map((w) => (
                            <button
                                key={w.id}
                                onClick={() => { setOpen(false); router.get(`/workspaces/${w.id}/switch`); }}
                                className="flex w-full items-center gap-2 px-3 py-2 text-sm text-zinc-700 hover:bg-zinc-50 transition-colors"
                            >
                                <div className="flex h-5 w-5 shrink-0 items-center justify-center rounded bg-indigo-100 text-xs font-semibold text-indigo-700">
                                    {w.name.charAt(0).toUpperCase()}
                                </div>
                                <span className="flex-1 truncate text-left">{w.name}</span>
                                {w.id === workspace.id && <Check className="h-3.5 w-3.5 text-indigo-600" />}
                            </button>
                        ))}
                    </div>
                </>
            )}
        </div>
    );
}

// ─── User menu ────────────────────────────────────────────────────────────────

function UserMenu({ name, email, isSuperAdmin, workspaceRole }: { name: string; email: string; isSuperAdmin: boolean; workspaceRole: 'owner' | 'admin' | 'member' | null | undefined }) {
    const [open, setOpen] = useState(false);

    return (
        <div className="relative">
            <button
                onClick={() => setOpen((v) => !v)}
                className="flex h-8 w-8 items-center justify-center rounded-full bg-indigo-100 text-sm font-semibold text-indigo-700 hover:bg-indigo-200 transition-colors"
                title={name}
            >
                {name.charAt(0).toUpperCase()}
            </button>

            {open && (
                <>
                    <div className="fixed inset-0 z-10" onClick={() => setOpen(false)} />
                    <div className="absolute right-0 top-full z-20 mt-1 w-52 rounded-lg border border-zinc-200 bg-white py-1 shadow-lg">
                        <div className="border-b border-zinc-100 px-3 py-2">
                            <div className="text-sm font-medium text-zinc-900 truncate">{name}</div>
                            <div className="text-xs text-zinc-400 truncate">{email}</div>
                        </div>
                        <Link href="/settings/profile" onClick={() => setOpen(false)} className="flex items-center gap-2 px-3 py-2 text-sm text-zinc-700 hover:bg-zinc-50 transition-colors">
                            <User className="h-4 w-4 text-zinc-400" /> Profile
                        </Link>
                        {(isSuperAdmin || workspaceRole === 'owner') && (
                            <Link href="/settings/billing" onClick={() => setOpen(false)} className="flex items-center gap-2 px-3 py-2 text-sm text-zinc-700 hover:bg-zinc-50 transition-colors">
                                <CreditCard className="h-4 w-4 text-zinc-400" /> Billing
                            </Link>
                        )}
                        {isSuperAdmin && (
                            <>
                                <div className="border-t border-zinc-100 mt-1 pt-1">
                                    <div className="px-3 py-1.5 text-xs font-medium text-zinc-400 uppercase tracking-wide">
                                        Admin
                                    </div>
                                    <Link href="/admin/overview" onClick={() => setOpen(false)} className="flex items-center gap-2 px-3 py-2 text-sm text-zinc-700 hover:bg-zinc-50 transition-colors">
                                        <ShieldCheck className="h-4 w-4 text-zinc-400" /> Overview
                                    </Link>
                                    <Link href="/admin/workspaces" onClick={() => setOpen(false)} className="flex items-center gap-2 px-3 py-2 text-sm text-zinc-700 hover:bg-zinc-50 transition-colors">
                                        <Building2 className="h-4 w-4 text-zinc-400" /> Workspaces
                                    </Link>
                                    <Link href="/admin/users" onClick={() => setOpen(false)} className="flex items-center gap-2 px-3 py-2 text-sm text-zinc-700 hover:bg-zinc-50 transition-colors">
                                        <Users className="h-4 w-4 text-zinc-400" /> Users
                                    </Link>
                                    <Link href="/admin/logs" onClick={() => setOpen(false)} className="flex items-center gap-2 px-3 py-2 text-sm text-zinc-700 hover:bg-zinc-50 transition-colors">
                                        <ScrollText className="h-4 w-4 text-zinc-400" /> Logs
                                    </Link>
                                </div>
                            </>
                        )}
                        <div className="border-t border-zinc-100 mt-1 pt-1">
                            <Link href="/logout" method="post" as="button" onClick={() => setOpen(false)} className="flex w-full items-center gap-2 px-3 py-2 text-sm text-red-600 hover:bg-red-50 transition-colors">
                                <LogOut className="h-4 w-4" /> Log out
                            </Link>
                        </div>
                    </div>
                </>
            )}
        </div>
    );
}

// ─── Alert bell ───────────────────────────────────────────────────────────────

function AlertBell({ count }: { count: number }) {
    return (
        <Link href="/insights" className="relative flex h-8 w-8 items-center justify-center rounded-lg text-zinc-500 hover:bg-zinc-100 hover:text-zinc-700 transition-colors" title="Alerts">
            <Bell className="h-4 w-4" />
            {count > 0 && (
                <span className="absolute -right-0.5 -top-0.5 flex h-4 w-4 items-center justify-center rounded-full bg-red-600 text-[10px] font-bold text-white">
                    {count > 9 ? '9+' : count}
                </span>
            )}
        </Link>
    );
}

// ─── Sidebar ──────────────────────────────────────────────────────────────────

function buildSettingsItems(
    role: 'owner' | 'admin' | 'member' | null | undefined,
    isSuperAdmin: boolean,
): FlatNavItem[] {
    const isOwnerOrAdmin = isSuperAdmin || role === 'owner' || role === 'admin';
    const isOwner        = isSuperAdmin || role === 'owner';

    return [
        { label: 'Profile', href: '/settings/profile', icon: User },
        ...(isOwnerOrAdmin ? [
            { label: 'Workspace',    href: '/settings/workspace',    icon: Settings },
            { label: 'Integrations', href: '/settings/integrations', icon: Puzzle },
            { label: 'Team',         href: '/settings/team',         icon: Users },
        ] : []),
        ...(isOwner ? [
            { label: 'Billing', href: '/settings/billing', icon: CreditCard },
        ] : []),
    ];
}

function buildNavEntries(
    stores: StoreType[],
    hasAdAccounts: boolean,
    hasGsc: boolean,
): NavEntry[] {
    // "All stores" always at top, individual stores listed below
    const storeChildren: FlatNavItem[] = [
        { label: 'All stores', href: '/stores', exact: true },
        ...stores.map((s) => ({ label: s.name, href: `/stores/${s.slug}/overview` })),
    ];

    return [
        {
            type: 'group',
            key: 'performance',
            label: 'Performance',
            icon: LayoutDashboard,
            basePaths: ['/dashboard', '/analytics', '/countries'],
            children: [
                { label: 'Overview',    href: '/dashboard' },
                { label: 'Daily',       href: '/analytics/daily' },
                { label: 'By Country',  href: '/countries' },
                { label: 'By Product',  href: '/analytics/products' },
            ],
        },
        {
            type: 'flat',
            label: 'Campaigns',
            href: '/campaigns',
            icon: BarChart2,
            indicator: !hasAdAccounts,
        },
        {
            type: 'group',
            key: 'stores',
            label: 'Stores',
            icon: Store,
            basePaths: ['/stores'],
            children: storeChildren,
        },
        {
            type: 'flat',
            label: 'SEO',
            href: '/seo',
            icon: Search,
            indicator: !hasGsc,
        },
        {
            type: 'flat',
            label: 'Insights',
            href: '/insights',
            icon: Lightbulb,
        },
    ];
}

function Sidebar({
    workspace,
    workspaces,
    stores,
    hasAdAccounts,
    hasGsc,
    isSuperAdmin,
    workspaceRole,
    onClose,
}: {
    workspace: Workspace | undefined;
    workspaces: Workspace[] | undefined;
    stores: StoreType[];
    hasAdAccounts: boolean;
    hasGsc: boolean;
    isSuperAdmin: boolean;
    workspaceRole: 'owner' | 'admin' | 'member' | null | undefined;
    onClose?: () => void;
}) {
    const pathname = typeof window !== 'undefined' ? window.location.pathname : '';

    const navEntries = buildNavEntries(stores, hasAdAccounts, hasGsc);

    // Auto-determine which group should be open based on current path
    const defaultOpen = (): string | null => {
        for (const entry of navEntries) {
            if (entry.type === 'group' && matchesPath(pathname, entry.basePaths)) {
                return entry.key;
            }
        }
        return null;
    };

    const [openGroup, setOpenGroup] = useState<string | null>(defaultOpen);

    // Re-compute when pathname changes (Inertia navigation)
    useEffect(() => {
        setOpenGroup(defaultOpen());
    // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [pathname]);

    const toggleGroup = (key: string) => {
        // Don't collapse the group whose path we're currently on
        if (defaultOpen() === key) return;
        setOpenGroup((prev) => (prev === key ? null : key));
    };

    const settingsActive = pathname.startsWith('/settings');

    return (
        <aside className="flex h-full w-[220px] flex-col border-r border-zinc-200 bg-white">
            {/* Logo */}
            <div className="flex h-14 shrink-0 items-center justify-between border-b border-zinc-100 px-4">
                <Link href="/dashboard" className="text-lg font-bold text-zinc-900 tracking-tight">
                    Nexstage
                </Link>
                {onClose && (
                    <button onClick={onClose} className="flex h-7 w-7 items-center justify-center rounded text-zinc-400 hover:text-zinc-600 lg:hidden">
                        <X className="h-4 w-4" />
                    </button>
                )}
            </div>

            {/* Main nav */}
            <nav className="flex-1 overflow-y-auto px-3 py-3 space-y-0.5">
                {navEntries.map((entry) => {
                    if (entry.type === 'group') {
                        return (
                            <SidebarGroupItem
                                key={entry.key}
                                group={entry}
                                isOpen={openGroup === entry.key}
                                onToggle={() => toggleGroup(entry.key)}
                                onClick={onClose}
                            />
                        );
                    }
                    return <SidebarLink key={entry.href} item={entry} onClick={onClose} />;
                })}

                {/* Settings */}
                <div className="pt-4">
                    <div className="px-3 pb-1.5 text-xs font-medium text-zinc-400 uppercase tracking-wide">
                        Settings
                    </div>
                    {buildSettingsItems(workspaceRole, isSuperAdmin).map((item) => (
                        <SidebarLink key={item.href} item={item} onClick={onClose} />
                    ))}
                </div>

                {/* Admin */}
                {isSuperAdmin && (
                    <div className="pt-4">
                        <div className="px-3 pb-1.5 text-xs font-medium text-zinc-400 uppercase tracking-wide">
                            Admin
                        </div>
                        <SidebarLink item={{ label: 'Overview',   href: '/admin/overview',    icon: ShieldCheck }} onClick={onClose} />
                        <SidebarLink item={{ label: 'Workspaces', href: '/admin/workspaces', icon: Building2 }}   onClick={onClose} />
                        <SidebarLink item={{ label: 'Users',      href: '/admin/users',       icon: Users }}       onClick={onClose} />
                        <SidebarLink item={{ label: 'Logs',       href: '/admin/logs',        icon: ScrollText }}  onClick={onClose} />
                    </div>
                )}

                {/* Dev */}
                {isSuperAdmin && (
                    <div className="pt-4">
                        <div className="px-3 pb-1.5 text-xs font-medium text-zinc-400 uppercase tracking-wide">
                            Dev
                        </div>
                        <SidebarLink item={{ label: 'Snippets', href: '/admin/dev/snippets', icon: FileCode2 }} onClick={onClose} />
                        <SidebarLink item={{ label: 'Debug',    href: '/admin/dev/debug',    icon: Bug }}       onClick={onClose} />
                    </div>
                )}
            </nav>

            {/* Workspace switcher */}
            <div className="shrink-0 border-t border-zinc-100 p-3">
                <WorkspaceSwitcher workspace={workspace} workspaces={workspaces} />
            </div>
        </aside>
    );
}

// ─── AppLayout ────────────────────────────────────────────────────────────────

interface AppLayoutProps {
    children: ReactNode;
    topBarRight?: ReactNode;
    dateRangePicker?: ReactNode;
}

export default function AppLayout({ children, topBarRight, dateRangePicker }: AppLayoutProps) {
    const {
        auth,
        workspace,
        workspaces,
        stores,
        unread_alerts_count,
        has_ad_accounts,
        has_gsc,
        workspace_role,
        impersonating,
        impersonated_user_name,
        flash,
    } = usePage<PageProps>().props;

    const isSuperAdmin = auth.user.is_super_admin;
    const [sidebarOpen, setSidebarOpen] = useState(false);

    useEffect(() => {
        if (flash?.success) toast.success(flash.success);
        if (flash?.error)   toast.error(flash.error);
    }, [flash?.success, flash?.error]);

    // Payment method warning banner logic
    const paymentBanner = (() => {
        if (!workspace) return null;
        const hasPaymentMethod = !!workspace.pm_type;
        if (hasPaymentMethod) return null;

        const now = Date.now();
        const trialEnd = workspace.trial_ends_at ? new Date(workspace.trial_ends_at).getTime() : null;
        const daysLeft = trialEnd ? Math.ceil((trialEnd - now) / 86_400_000) : null;
        const trialActive = daysLeft !== null && daysLeft > 0;
        const hasPlan = !!workspace.billing_plan;

        if (hasPlan) {
            // Active subscription but no payment method on file
            return {
                message: 'No payment method on file. Your subscription may be cancelled. Please add one in billing settings.',
                severity: 'warning' as const,
                action: { label: 'Go to billing', href: route('settings.billing') },
            };
        }

        if (trialActive && daysLeft !== null && daysLeft <= 7) {
            return {
                message: `Your trial ends in ${daysLeft} day${daysLeft === 1 ? '' : 's'}. Add a payment method to keep access.`,
                severity: daysLeft <= 3 ? 'critical' as const : 'warning' as const,
                action: { label: 'Add payment method', href: route('settings.billing') },
            };
        }

        return null;
    })();

    return (
        <div className="flex h-screen bg-zinc-50 overflow-hidden">
            {/* Mobile overlay */}
            {sidebarOpen && (
                <div className="fixed inset-0 z-20 bg-black/40 lg:hidden" onClick={() => setSidebarOpen(false)} />
            )}

            {/* Sidebar */}
            <div className={cn(
                'fixed inset-y-0 left-0 z-30 w-[220px] transition-transform duration-200 lg:static lg:translate-x-0 lg:z-auto',
                sidebarOpen ? 'translate-x-0' : '-translate-x-full',
            )}>
                <Sidebar
                    workspace={workspace}
                    workspaces={workspaces}
                    stores={stores ?? []}
                    hasAdAccounts={has_ad_accounts ?? false}
                    hasGsc={has_gsc ?? false}
                    isSuperAdmin={isSuperAdmin}
                    workspaceRole={workspace_role}
                    onClose={() => setSidebarOpen(false)}
                />
            </div>

            {/* Main area */}
            <div className="flex flex-1 min-w-0 flex-col overflow-hidden">
                {impersonating && (
                    <div className="flex shrink-0 items-center justify-between bg-red-600 px-4 py-2 text-sm text-white">
                        <span>Impersonating <strong>{impersonated_user_name}</strong></span>
                        <Link href="/admin/impersonation/stop" method="post" as="button" className="ml-4 underline font-medium hover:no-underline">
                            Stop impersonating
                        </Link>
                    </div>
                )}

                {paymentBanner && (
                    <AlertBanner
                        message={paymentBanner.message}
                        severity={paymentBanner.severity}
                        action={paymentBanner.action}
                    />
                )}

                {/* Top bar */}
                <header className="flex h-14 shrink-0 items-center gap-3 border-b border-zinc-200 bg-white px-4">
                    <button
                        onClick={() => setSidebarOpen(true)}
                        className="flex h-8 w-8 items-center justify-center rounded-lg text-zinc-500 hover:bg-zinc-100 lg:hidden"
                    >
                        <Menu className="h-4 w-4" />
                    </button>
                    <div className="flex flex-1 items-center gap-2 min-w-0">
                        {dateRangePicker}
                    </div>
                    <div className="flex shrink-0 items-center gap-1">
                        {topBarRight}
                        <AlertBell count={unread_alerts_count ?? 0} />
                        <UserMenu name={auth.user.name} email={auth.user.email} isSuperAdmin={isSuperAdmin} workspaceRole={workspace_role} />
                    </div>
                </header>

                {/* Page content */}
                <main className="flex-1 overflow-y-auto">
                    <div className="mx-auto max-w-[1400px] px-6 py-6">
                        {children}
                    </div>
                </main>
            </div>

            <Toaster />
        </div>
    );
}
