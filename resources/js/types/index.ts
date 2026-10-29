export interface AdminUser {
    id: number;
    name: string;
    mobile_number: string;
    email: string | null;
    status: string;
    permissions: { id: number; name: string; module: string | null }[];
}
export interface AdminPageProps {
    [key: string]: unknown;
    auth: { user: AdminUser | null };
    csrf_token: string;
}
