import { Link, router, usePage } from '@inertiajs/react';
import { DataFreshness } from '@/Components/shared/DataFreshness';
import { Toaster } from '@/Components/ui/sonner';
import { toast } from 'sonner';
import { AlertBanner } from '@/Components/shared/AlertBanner';
import {
    LayoutDashboard,
    Store,
    BarChart2,
    TrendingUp,
    Lightbulb,
    Bell,
    CalendarDays,
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
    ChevronRight,
    Building2,
    ScrollText,
    ListOrdered,
    Bug,
    FileCode2,
    Gauge,
    Layers,
    Settings,
    Tag,
    Activity,
    ShieldAlert,
    GitBranch,
    Plus,
    DollarSign,
} from 'lucide-react';
import { ReactNode, useEffect, useState } from 'react';
import { cn } from '@/lib/utils';
import { wurl } from '@/lib/workspace-url';
import { PageProps, Workspace } from '@/types';

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
                    ? 'bg-primary/10 text-primary'
                    : 'text-zinc-600 hover:bg-zinc-100 hover:text-zinc-900',
            )}
        >
            {Icon && (
                <Icon className={cn('h-4 w-4 shrink-0', active ? 'text-primary' : 'text-zinc-400 group-hover:text-zinc-600')} />
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
                        ? 'text-primary'
                        : 'text-zinc-600 hover:bg-zinc-100 hover:text-zinc-900',
                )}
            >
                <Icon className={cn('h-4 w-4 shrink-0', active ? 'text-primary' : 'text-zinc-400')} />
                <span className="flex-1 text-left">{group.label}</span>
                <ChevronRight
                    className={cn(
                        'h-3.5 w-3.5 shrink-0 transition-transform',
                        active ? 'text-primary/60' : 'text-zinc-300',
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
                <div className="flex h-6 w-6 shrink-0 items-center justify-center rounded bg-primary text-xs font-bold text-primary-foreground">
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
                                onClick={() => { setOpen(false); router.post(`/workspaces/${w.id}/switch`); }}
                                className="flex w-full items-center gap-2 px-3 py-2 text-sm text-zinc-700 hover:bg-zinc-50 transition-colors"
                            >
                                <div className="flex h-5 w-5 shrink-0 items-center justify-center rounded bg-primary/15 text-xs font-semibold text-primary">
                                    {w.name.charAt(0).toUpperCase()}
                                </div>
                                <span className="flex-1 truncate text-left">{w.name}</span>
                                {w.id === workspace.id && <Check className="h-3.5 w-3.5 text-primary" />}
                            </button>
                        ))}
                        <div className="my-1 border-t border-zinc-100" />
                        <button
                            onClick={() => { setOpen(false); router.post(route('workspaces.create')); }}
                            className="flex w-full items-center gap-2 px-3 py-2 text-sm text-zinc-500 hover:bg-zinc-50 hover:text-zinc-700 transition-colors"
                        >
                            <Plus className="h-3.5 w-3.5" />
                            New workspace
                        </button>
                    </div>
                </>
            )}
        </div>
    );
}

// ─── User menu ────────────────────────────────────────────────────────────────

function UserMenu({
    name,
    email,
    isSuperAdmin,
    workspaceRole,
    workspaceSlug,
}: {
    name: string;
    email: string;
    isSuperAdmin: boolean;
    workspaceRole: 'owner' | 'admin' | 'member' | null | undefined;
    workspaceSlug: string | undefined;
}) {
    const [open, setOpen] = useState(false);
    const w = (path: string) => wurl(workspaceSlug, path);
    const isOwnerOrAdmin = isSuperAdmin || workspaceRole === 'owner' || workspaceRole === 'admin';
    const isOwner        = isSuperAdmin || workspaceRole === 'owner';

    const close = () => setOpen(false);

    return (
        <div className="relative">
            <button
                onClick={() => setOpen((v) => !v)}
                className="flex h-8 w-8 items-center justify-center rounded-full bg-primary/15 text-sm font-semibold text-primary hover:bg-primary/20 transition-colors"
                title={name}
            >
                {name.charAt(0).toUpperCase()}
            </button>

            {open && (
                <>
                    <div className="fixed inset-0 z-10" onClick={close} />
                    <div className="absolute right-0 top-full z-20 mt-1 w-56 rounded-lg border border-zinc-200 bg-white py-1 shadow-lg">
                        {/* User info */}
                        <div className="border-b border-zinc-100 px-3 py-2">
                            <div className="text-sm font-medium text-zinc-900 truncate">{name}</div>
                            <div className="text-xs text-zinc-400 truncate">{email}</div>
                        </div>

                        {/* Personal */}
                        <Link href={w('/settings/profile')} onClick={close} className="flex items-center gap-2 px-3 py-2 text-sm text-zinc-700 hover:bg-zinc-50 transition-colors">
                            <User className="h-4 w-4 text-zinc-400" /> Profile
                        </Link>
                        <Link href={w('/settings/notifications')} onClick={close} className="flex items-center gap-2 px-3 py-2 text-sm text-zinc-700 hover:bg-zinc-50 transition-colors">
                            <Bell className="h-4 w-4 text-zinc-400" /> Notifications
                        </Link>

                        {/* Workspace settings (owner / admin) */}
                        {isOwnerOrAdmin && (
                            <div className="border-t border-zinc-100 mt-1 pt-1">
                                <div className="px-3 py-1 text-[10px] font-semibold text-zinc-400 uppercase tracking-widest">Workspace</div>
                                <Link href={w('/settings/workspace')} onClick={close} className="flex items-center gap-2 px-3 py-2 text-sm text-zinc-700 hover:bg-zinc-50 transition-colors">
                                    <Settings className="h-4 w-4 text-zinc-400" /> Settings
                                </Link>
                                <Link href={w('/settings/integrations')} onClick={close} className="flex items-center gap-2 px-3 py-2 text-sm text-zinc-700 hover:bg-zinc-50 transition-colors">
                                    <Puzzle className="h-4 w-4 text-zinc-400" /> Integrations
                                </Link>
                                <Link href={w('/settings/team')} onClick={close} className="flex items-center gap-2 px-3 py-2 text-sm text-zinc-700 hover:bg-zinc-50 transition-colors">
                                    <Users className="h-4 w-4 text-zinc-400" /> Team
                                </Link>
                                <Link href={w('/settings/events')} onClick={close} className="flex items-center gap-2 px-3 py-2 text-sm text-zinc-700 hover:bg-zinc-50 transition-colors">
                                    <CalendarDays className="h-4 w-4 text-zinc-400" /> Events
                                </Link>
                                {isOwner && (
                                    <Link href={w('/settings/billing')} onClick={close} className="flex items-center gap-2 px-3 py-2 text-sm text-zinc-700 hover:bg-zinc-50 transition-colors">
                                        <CreditCard className="h-4 w-4 text-zinc-400" /> Billing
                                    </Link>
                                )}
                            </div>
                        )}

                        {/* Admin + Dev (super_admin only) */}
                        {isSuperAdmin && (
                            <div className="border-t border-zinc-100 mt-1 pt-1">
                                <div className="px-3 py-1 text-[10px] font-semibold text-zinc-400 uppercase tracking-widest">Admin</div>
                                <Link href="/admin/overview" onClick={close} className="flex items-center gap-2 px-3 py-2 text-sm text-zinc-700 hover:bg-zinc-50 transition-colors">
                                    <ShieldCheck className="h-4 w-4 text-zinc-400" /> Overview
                                </Link>
                                <Link href="/admin/workspaces" onClick={close} className="flex items-center gap-2 px-3 py-2 text-sm text-zinc-700 hover:bg-zinc-50 transition-colors">
                                    <Building2 className="h-4 w-4 text-zinc-400" /> Workspaces
                                </Link>
                                <Link href="/admin/users" onClick={close} className="flex items-center gap-2 px-3 py-2 text-sm text-zinc-700 hover:bg-zinc-50 transition-colors">
                                    <Users className="h-4 w-4 text-zinc-400" /> Users
                                </Link>
                                <Link href="/admin/logs" onClick={close} className="flex items-center gap-2 px-3 py-2 text-sm text-zinc-700 hover:bg-zinc-50 transition-colors">
                                    <ScrollText className="h-4 w-4 text-zinc-400" /> Logs
                                </Link>
                                <Link href="/admin/queue" onClick={close} className="flex items-center gap-2 px-3 py-2 text-sm text-zinc-700 hover:bg-zinc-50 transition-colors">
                                    <ListOrdered className="h-4 w-4 text-zinc-400" /> Queue
                                </Link>
                                <Link href="/admin/system-health" onClick={close} className="flex items-center gap-2 px-3 py-2 text-sm text-zinc-700 hover:bg-zinc-50 transition-colors">
                                    <Activity className="h-4 w-4 text-zinc-400" /> System Health
                                </Link>
                                <Link href="/admin/silent-alerts" onClick={close} className="flex items-center gap-2 px-3 py-2 text-sm text-zinc-700 hover:bg-zinc-50 transition-colors">
                                    <ShieldAlert className="h-4 w-4 text-zinc-400" /> Silent Alerts
                                </Link>
                                <Link href="/admin/channel-mappings" onClick={close} className="flex items-center gap-2 px-3 py-2 text-sm text-zinc-700 hover:bg-zinc-50 transition-colors">
                                    <GitBranch className="h-4 w-4 text-zinc-400" /> Channels
                                </Link>
                                <div className="px-3 py-1 mt-1 text-[10px] font-semibold text-zinc-400 uppercase tracking-widest">Dev</div>
                                <Link href="/admin/dev/snippets" onClick={close} className="flex items-center gap-2 px-3 py-2 text-sm text-zinc-700 hover:bg-zinc-50 transition-colors">
                                    <FileCode2 className="h-4 w-4 text-zinc-400" /> Snippets
                                </Link>
                                <Link href="/admin/dev/debug" onClick={close} className="flex items-center gap-2 px-3 py-2 text-sm text-zinc-700 hover:bg-zinc-50 transition-colors">
                                    <Bug className="h-4 w-4 text-zinc-400" /> Debug
                                </Link>
                            </div>
                        )}

                        {/* Logout */}
                        <div className="border-t border-zinc-100 mt-1 pt-1">
                            <Link href="/logout" method="post" as="button" onClick={close} className="flex w-full items-center gap-2 px-3 py-2 text-sm text-red-600 hover:bg-red-50 transition-colors">
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

function AlertBell({ count, workspaceSlug }: { count: number; workspaceSlug: string | undefined }) {
    const href = wurl(workspaceSlug, '/inbox');
    return (
        <Link href={href} className="relative flex h-8 w-8 items-center justify-center rounded-lg text-zinc-500 hover:bg-zinc-100 hover:text-zinc-700 transition-colors" title="Alerts">
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

// Section header rendered between nav groups
function SectionLabel({ label }: { label: string }) {
    return (
        <div className="px-3 pt-4 pb-1 text-[10px] font-semibold text-zinc-400 uppercase tracking-widest select-none">
            {label}
        </div>
    );
}

function Sidebar({
    workspace,
    workspaces,
    onClose,
}: {
    workspace: Workspace | undefined;
    workspaces: Workspace[] | undefined;
    onClose?: () => void;
}) {
    const pathname = typeof window !== 'undefined' ? window.location.pathname : '';
    const slug = workspace?.slug;
    const w = (path: string) => wurl(slug, path);

    // Manage group — tools & store management
    const manageGroup: NavGroup = {
        type: 'group',
        key: 'manage',
        label: 'Manage',
        icon: Settings,
        basePaths: [w('/manage'), w('/stores'), w('/holidays')],
        children: [
            { label: 'Tag Generator',     href: w('/manage/tag-generator'),     icon: Tag },
            { label: 'Channel Mappings',  href: w('/manage/channel-mappings'),  icon: GitBranch },
            { label: 'Naming Convention', href: w('/manage/naming-convention'), icon: FileCode2 },
            { label: 'Product Costs',     href: w('/manage/product-costs'),     icon: DollarSign },
            { label: 'Stores',            href: w('/stores'),                   icon: Store },
            { label: 'Holidays',          href: w('/holidays'),                 icon: CalendarDays },
        ],
    };

    const isManageOpen = matchesPath(pathname, manageGroup.basePaths);
    const [manageOpen, setManageOpen] = useState(isManageOpen);

    useEffect(() => {
        setManageOpen(matchesPath(pathname, manageGroup.basePaths));
    // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [pathname]);

    return (
        <aside className="flex h-full w-[220px] flex-col border-r border-zinc-200 bg-white">
            {/* Logo */}
            <div className="flex h-14 shrink-0 items-center justify-between border-b border-zinc-100 px-4">
                <Link href={w('')} className="text-lg font-bold text-zinc-900 tracking-tight">
                    Nexstage
                </Link>
                {onClose && (
                    <button onClick={onClose} className="flex h-7 w-7 items-center justify-center rounded text-zinc-400 hover:text-zinc-600 lg:hidden">
                        <X className="h-4 w-4" />
                    </button>
                )}
            </div>

            {/* Main nav — 8 destinations */}
            <nav className="flex-1 overflow-y-auto px-3 py-3">
                <div className="space-y-0.5">
                    {/* 1. Home */}
                    <SidebarLink
                        item={{ label: 'Home', href: w(''), icon: LayoutDashboard, exact: true }}
                        onClick={onClose}
                    />
                    {/* 2. Acquisition */}
                    <SidebarLink
                        item={{ label: 'Acquisition', href: w('/acquisition'), icon: Layers }}
                        onClick={onClose}
                    />
                    {/* 3. Campaigns & Creatives */}
                    <SidebarLink
                        item={{ label: 'Campaigns & Creatives', href: w('/campaigns'), icon: BarChart2, indicator: !(workspace?.has_ads ?? false) }}
                        onClick={onClose}
                    />
                    {/* 4. Organic */}
                    <SidebarLink
                        item={{ label: 'Organic', href: w('/seo'), icon: TrendingUp, indicator: !(workspace?.has_gsc ?? false) }}
                        onClick={onClose}
                    />
                    {/* 5. Performance */}
                    <SidebarLink
                        item={{ label: 'Performance', href: w('/performance'), icon: Gauge, indicator: !(workspace?.has_psi ?? false) }}
                        onClick={onClose}
                    />
                    {/* 6. Store */}
                    <SidebarLink
                        item={{ label: 'Store', href: w('/store'), icon: Store }}
                        onClick={onClose}
                    />
                </div>

                <div className="mt-3 space-y-0.5">
                    {/* 7. Inbox */}
                    <SidebarLink
                        item={{ label: 'Inbox', href: w('/inbox'), icon: Lightbulb }}
                        onClick={onClose}
                    />
                    {/* 8. Manage */}
                    <SidebarGroupItem
                        group={manageGroup}
                        isOpen={manageOpen}
                        onToggle={() => setManageOpen((v) => !v)}
                        onClick={onClose}
                    />
                </div>
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
        workspace_role,
        impersonating,
        impersonated_user_name,
        flash,
    } = usePage<PageProps>().props;

    const isSuperAdmin = auth.user.is_super_admin;
    const [sidebarOpen, setSidebarOpen] = useState(false);
    const slug = workspace?.slug;
    const w = (path: string) => wurl(slug, path);

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
            return {
                message: 'No payment method on file. Your subscription may be cancelled. Please add one in billing settings.',
                severity: 'warning' as const,
                action: { label: 'Go to billing', href: w('/settings/billing') },
            };
        }

        if (trialActive && daysLeft !== null && daysLeft <= 7) {
            return {
                message: `Your trial ends in ${daysLeft} day${daysLeft === 1 ? '' : 's'}. Add a payment method to keep access.`,
                severity: daysLeft <= 3 ? 'critical' as const : 'warning' as const,
                action: { label: 'Add payment method', href: w('/settings/billing') },
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
                    <div className="flex shrink-0 items-center gap-3">
                        <DataFreshness />
                        {topBarRight}
                        <AlertBell count={unread_alerts_count ?? 0} workspaceSlug={slug} />
                        <UserMenu
                            name={auth.user.name}
                            email={auth.user.email}
                            isSuperAdmin={isSuperAdmin}
                            workspaceRole={workspace_role}
                            workspaceSlug={slug}
                        />
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
