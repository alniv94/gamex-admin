<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import BrandLogo from '../../components/BrandLogo.vue';
import { ApiError, useAdminApi } from '../../composables/useAdminApi';

const api = useAdminApi();
const mobile = ref('');
const otp = ref('');
const challenge = ref('');
const challengeMobile = ref('');
const sending = ref(false);
const signingIn = ref(false);
const message = ref('');
const errors = ref<Record<string, string[]>>({});
const captured = ref(false);
const remaining = ref(0);
const mobileTouched = ref(false);
const otpTouched = ref(false);
const notification = ref('');
const snackbar = ref(false);
const mobileField = ref<{ focus: () => void } | null>(null);
const otpField = ref<{ focus: () => void } | null>(null);
const busy = computed(() => sending.value || signingIn.value);
const validMobile = computed(() => /^9[0-9]{9}$/.test(mobile.value));
const mobileErrors = computed(
    () =>
        errors.value.mobile_number ??
        (mobileTouched.value && !validMobile.value
            ? ['Enter a valid Philippine mobile number.']
            : []),
);
const otpErrors = computed(
    () =>
        errors.value.otp ??
        (otpTouched.value && !/^[0-9]{6}$/.test(otp.value) ? ['Enter the 6-digit OTP.'] : []),
);
const timer = window.setInterval(() => {
    if (remaining.value > 0) remaining.value--;
}, 1000);
onUnmounted(() => window.clearInterval(timer));

function normalizeMobile(value: string) {
    return value
        .replace(/[\s()-]/g, '')
        .replace(/^\+?63/, '')
        .replace(/^0+/, '');
}
watch(mobile, (value) => {
    const normalized = normalizeMobile(value);
    if (normalized !== value) {
        mobile.value = normalized;
        return;
    }
    delete errors.value.mobile_number;
    if (challengeMobile.value && value !== challengeMobile.value) {
        challenge.value = '';
        challengeMobile.value = '';
        otp.value = '';
        captured.value = false;
        otpTouched.value = false;
        remaining.value = 0;
        delete errors.value.otp;
    }
});
watch(otp, () => {
    delete errors.value.otp;
});

onMounted(async () => {
    try {
        const saved = window.localStorage.getItem('mobile_number');
        if (saved && /^9[0-9]{9}$/.test(normalizeMobile(saved)))
            mobile.value = normalizeMobile(saved);
    } catch {
        /* Sign-in remains available when browser storage is disabled. */
    }
    await nextTick();
    if (validMobile.value) otpField.value?.focus();
    else mobileField.value?.focus();
});

function pasteMobile(event: ClipboardEvent) {
    const value = normalizeMobile(event.clipboardData?.getData('text') || '');
    if (/^9[0-9]{9}$/.test(value)) {
        event.preventDefault();
        mobile.value = value;
    }
}
function showError(error: unknown, fallback: string) {
    if (error instanceof ApiError) errors.value = error.errors;
    message.value = Object.keys(errors.value).length
        ? ''
        : error instanceof Error
          ? error.message
          : fallback;
}
async function sendCode() {
    mobileTouched.value = true;
    if (busy.value || !validMobile.value || remaining.value > 0) return;
    sending.value = true;
    message.value = '';
    errors.value = {};
    try {
        const result = await api<{
            challenge_id: string;
            delivery: string;
            resend_after: number;
            message: string;
        }>('/api/login/otp', { mobile_number: mobile.value });
        challenge.value = result.challenge_id;
        challengeMobile.value = mobile.value;
        otp.value = '';
        otpTouched.value = false;
        captured.value = result.delivery === 'captured';
        remaining.value = result.resend_after;
        notification.value = captured.value
            ? 'Local test mode: no SMS was sent. The OTP was captured privately.'
            : result.message;
        snackbar.value = true;
        await nextTick();
        otpField.value?.focus();
    } catch (error) {
        showError(error, 'Unable to request an OTP.');
    } finally {
        sending.value = false;
    }
}
async function signIn() {
    mobileTouched.value = true;
    otpTouched.value = true;
    message.value = '';
    if (busy.value || !validMobile.value || !/^[0-9]{6}$/.test(otp.value)) return;
    if (!challenge.value || challengeMobile.value !== mobile.value) {
        errors.value.otp = ['Please request an OTP for this mobile number first.'];
        return;
    }
    signingIn.value = true;
    errors.value = {};
    try {
        await api('/api/login', { challenge_id: challenge.value, otp: otp.value });
        try {
            window.localStorage.setItem('mobile_number', mobile.value);
        } catch {
            /* Optional convenience only. */
        }
        router.visit('/dashboard', { replace: true });
    } catch (error) {
        showError(error, 'Unable to sign in.');
    } finally {
        signingIn.value = false;
    }
}
</script>

<template>
    <Head title="Sign In" />
    <v-app>
        <v-main class="login-main">
            <div class="login-container">
                <v-card class="login-card shadow">
                    <BrandLogo />
                    <h1 class="login-heading">Sign In</h1>
                    <p class="login-intro">
                        Please sign-in to your account and start managing our games.
                    </p>
                    <form novalidate @submit.prevent="signIn">
                        <div class="login-fields">
                            <v-text-field
                                id="mobile-number"
                                ref="mobileField"
                                v-model="mobile"
                                name="mobile_number"
                                label="Mobile Number"
                                aria-label="Mobile Number"
                                prefix="+63"
                                active
                                autocomplete="tel-national"
                                type="tel"
                                inputmode="numeric"
                                maxlength="10"
                                :error-messages="mobileErrors"
                                :disabled="busy"
                                @blur="mobileTouched = true"
                                @paste="pasteMobile"
                            >
                                <template #append-inner>
                                    <button
                                        class="send-otp"
                                        type="button"
                                        :disabled="!validMobile || busy || remaining > 0"
                                        @click="sendCode"
                                    >
                                        <v-progress-circular
                                            v-if="sending"
                                            indeterminate
                                            :size="14"
                                            :width="2"
                                            color="primary"
                                            aria-label="Sending OTP"
                                        />
                                        <span v-else>Send OTP</span>
                                        <span v-if="remaining > 0"> ({{ remaining }})</span>
                                    </button>
                                </template>
                            </v-text-field>
                            <v-text-field
                                id="otp-code"
                                ref="otpField"
                                v-model="otp"
                                name="otp"
                                label="OTP"
                                aria-label="OTP"
                                placeholder="XXXXXX"
                                autocomplete="one-time-code"
                                inputmode="numeric"
                                maxlength="6"
                                :error-messages="otpErrors"
                                :disabled="busy"
                                @blur="otpTouched = otp.length > 0"
                            />
                        </div>
                        <v-alert
                            v-if="message"
                            class="mt-4"
                            type="error"
                            variant="tonal"
                            density="compact"
                            role="alert"
                            >{{ message }}</v-alert
                        >
                        <p v-if="captured" class="capture-note" role="status">
                            Local test mode: no SMS was sent. The OTP is in the private
                            notifications folder.
                        </p>
                        <v-btn
                            class="login-submit mt-6"
                            block
                            color="primary"
                            type="submit"
                            :loading="signingIn"
                            :disabled="sending"
                        >
                            <v-icon class="login-submit-icon" icon="mdi-login" size="18" />
                            Sign In
                        </v-btn>
                    </form>
                </v-card>
            </div>
        </v-main>
        <v-snackbar v-model="snackbar" location="top center" color="success" :timeout="5000">
            <v-icon icon="mdi-check-circle-outline" class="mr-2" size="20" />{{ notification }}
            <template #actions
                ><v-btn
                    icon="mdi-close"
                    variant="text"
                    size="small"
                    aria-label="Close notification"
                    @click="snackbar = false"
            /></template>
        </v-snackbar>
    </v-app>
</template>
