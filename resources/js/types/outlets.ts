export interface Outlet {
    id: number;
    outlet_name: string | null;
    barangay_id: number | null;
    address: string | null;
    is_active: 'Active' | 'Inactive' | null;
    created_at: string | null;
    version: number;
    barangay: string | null;
    city_id: number | null;
    city: string | null;
}

export interface OutletLocation {
    id: number;
    name: string;
}

export type OutletSort = 'outlet_name' | 'address' | 'is_active' | 'created_at';
