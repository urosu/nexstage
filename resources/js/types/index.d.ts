export interface User {
    id: number;
    name: string;
    email: string;
    email_verified_at?: string;
    is_super_admin: boolean;
    last_login_at?: string;
}

export interface Workspace {
    id: number;
    name: string;
    slug: string;
    reporting_currency: string;
    reporting_timezone: string;
    trial_ends_at?: string;
    billing_plan?: string;
    pm_type?: string | null;
}

export interface Store {
    id: number;
    slug: string;
    name: string;
    status: string;
}

export interface Alert {
    id: number;
    type: string;
    severity: 'info' | 'warning' | 'critical';
    data?: Record<string, unknown>;
    read_at?: string;
    resolved_at?: string;
    created_at: string;
}

export interface AdminWorkspace {
    id: number;
    name: string;
    slug: string;
    billing_plan: string | null;
    trial_ends_at: string | null;
    stores_count: number;
    owner: { id: number; name: string; email: string } | null;
    created_at: string;
    deleted_at: string | null;
}

export interface AdminUser {
    id: number;
    name: string;
    email: string;
    is_super_admin: boolean;
    workspaces_count: number;
    last_login_at: string | null;
    created_at: string;
}

export type PageProps<
    T extends Record<string, unknown> = Record<string, unknown>,
> = T & {
    auth: {
        user: User;
    };
    workspace?: Workspace;
    workspaces?: Workspace[];
    stores?: Store[];
    unread_alerts_count?: number;
    has_ad_accounts?: boolean;
    has_gsc?: boolean;
    workspace_role?: 'owner' | 'admin' | 'member' | null;
    impersonating?: boolean;
    impersonated_user_name?: string | null;
    earliest_date?: string | null;
    flash?: {
        success?: string | null;
        error?: string | null;
    };
};
