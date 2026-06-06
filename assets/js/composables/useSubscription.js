import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'

export function useSubscription() {
    const page = usePage()
    const plan = computed(() => page.props.auth?.user?.subscriptionPlan ?? 'free')
    const isPro = computed(() => plan.value === 'pro')

    return { plan, isPro }
}
