export interface ManagedUser {
    id: number;
    name: string | null;
    mobile_number: string | null;
    email: string | null;
    status: 'Active' | 'Inactive';
    version: number;
    roles: number[];
    direct_permissions: string[];
    inherited_permissions: string[];
    effective_permissions: string[];
}

export interface ManagedRole {
    id: number;
    name: string;
    version: number;
    permissions: string[];
    users_count: number;
}

export interface AdminPermission {
    name: string;
    label: string;
    group: string;
}
