import { ref, computed } from 'vue'
import { usePage } from '@inertiajs/vue3'

export function useUnits() {
    const page = usePage()
    const system = computed(() => page.props.auth?.preferences?.unitSystem ?? 'metric')

    const formatDistance = (meters) => {
        if (!meters) return '--'
        if (system.value === 'imperial') {
            return `${(meters / 1609.344).toFixed(2)} mi`
        }
        return `${(meters / 1000).toFixed(2)} km`
    }

    const formatElevation = (meters) => {
        if (!meters) return '--'
        if (system.value === 'imperial') {
            return `${Math.round(meters * 3.28084)} ft`
        }
        return `${Math.round(meters)} m`
    }

    const formatPace = (metersPerSecond) => {
        if (!metersPerSecond || metersPerSecond <= 0) return '--:--'
        const divisor = system.value === 'imperial' ? 1609.344 : 1000
        const paceSeconds = divisor / metersPerSecond
        const min = Math.floor(paceSeconds / 60)
        const sec = Math.round(paceSeconds % 60)
        const unit = system.value === 'imperial' ? '/mi' : '/km'
        return `${min}:${String(sec).padStart(2, '0')}${unit}`
    }

    const formatDuration = (seconds) => {
        if (!seconds) return '--'
        const h = Math.floor(seconds / 3600)
        const m = Math.floor((seconds % 3600) / 60)
        const s = seconds % 60
        if (h > 0) return `${h}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`
        return `${m}:${String(s).padStart(2, '0')}`
    }

    return { system, formatDistance, formatElevation, formatPace, formatDuration }
}
