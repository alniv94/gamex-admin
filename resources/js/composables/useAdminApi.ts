import { usePage } from '@inertiajs/vue3';
import type { AdminPageProps } from '../types';

export class ApiError extends Error {
    constructor(
        message: string,
        public errors: Record<string, string[]> = {},
    ) {
        super(message);
    }
}

export function useAdminApi() {
    const page = usePage<AdminPageProps>();
    return async <T>(
        url: string,
        body: Record<string, unknown> = {},
        method: 'GET' | 'POST' = 'POST',
    ): Promise<T> => {
        let response: Response;
        try {
            const options: RequestInit = {
                method,
                credentials: 'same-origin',
                cache: 'no-store',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': page.props.csrf_token,
                },
            };
            if (method === 'POST') options.body = JSON.stringify(body);
            response = await fetch(url, options);
        } catch {
            throw new ApiError('Unable to connect. Check your connection and try again.');
        }
        if (response.status === 419) {
            throw new ApiError('This page has expired. Refresh the page and try again.');
        }
        if (response.status === 401) {
            window.location.assign('/login');
            throw new ApiError('Your session has ended. Please sign in again.');
        }
        const data = (await response.json().catch(() => ({}))) as {
            message?: string;
            errors?: Record<string, string[]>;
        };
        if (!response.ok) {
            throw new ApiError(
                data.message || 'Something went wrong. Please try again.',
                data.errors,
            );
        }
        return data as T;
    };
}
