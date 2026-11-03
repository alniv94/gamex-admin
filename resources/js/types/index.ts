export interface AdminUser {
    id: number;
    name: string;
    mobile_number: string;
    email: string | null;
    status: string;
    permissions: string[];
    roles: string[];
}
export interface AdminPageProps {
    [key: string]: unknown;
    auth: { user: AdminUser | null };
    csrf_token: string;
    endingReportNavigation: { report: string; title: string; permission: string; icon: string }[];
    pagcorReportNavigation: { report: string; title: string; permission: string; icon: string }[];
}
