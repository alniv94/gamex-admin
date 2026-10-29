<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AdminLayout from '../layouts/AdminLayout.vue';
import type { AdminPageProps } from '../types';

const page = usePage<AdminPageProps>();
const user = computed(() => page.props.auth.user);
</script>

<template>
    <Head title="Dashboard" />
    <AdminLayout>
        <div v-if="user" class="dashboard-content">
            <h1 class="page-heading">Dashboard</h1>
            <v-card class="shadow account-card">
                <h2 class="section-heading">Your account</h2>
                <p class="account-welcome">Welcome, {{ user.name || 'Administrator' }}.</p>
                <dl class="account-details">
                    <div>
                        <dt>Full name</dt>
                        <dd>{{ user.name || '—' }}</dd>
                    </div>
                    <div>
                        <dt>Mobile Number</dt>
                        <dd>{{ user.mobile_number }}</dd>
                    </div>
                    <div>
                        <dt>Email address</dt>
                        <dd>{{ user.email || 'Not provided' }}</dd>
                    </div>
                    <div>
                        <dt>Status</dt>
                        <dd>
                            <v-chip color="success" variant="tonal" size="small">Active</v-chip>
                        </dd>
                    </div>
                    <div>
                        <dt>Assigned permissions</dt>
                        <dd>{{ user.permissions.length }} permissions</dd>
                    </div>
                </dl>
            </v-card>
        </div>
    </AdminLayout>
</template>
