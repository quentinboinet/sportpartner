<script setup>
import { computed } from 'vue'
import { useUnits } from '@/composables/useUnits'
import { useSubscription } from '@/composables/useSubscription'
import StatCard from '@/components/ui/StatCard.vue'
import WeeklyVolumeChart from '@/components/charts/WeeklyVolumeChart.vue'

const props = defineProps({
    recentActivities: Array,
    weeklyVolume: Array,
    stravaConnected: Boolean,
    isPro: Boolean,
})

const { formatDistance, formatDuration, formatPace } = useUnits()
const { isPro } = useSubscription()

const lastWeekStats = computed(() => {
    const last = props.weeklyVolume?.at(-1)
    if (!last) return { distance: 0, time: 0, count: 0 }
    return {
        distance: last.totalDistance,
        time: last.totalTime,
        count: last.count,
    }
})
</script>

<template>
    <div class="max-w-6xl mx-auto px-4 py-8">

        <!-- Strava connect banner -->
        <div v-if="!stravaConnected" class="mb-6 p-4 bg-orange-50 border border-orange-200 rounded-xl flex items-center justify-between">
            <div>
                <p class="font-medium text-orange-800">Connecte ton compte Strava</p>
                <p class="text-sm text-orange-600">Synchronise tes activités automatiquement</p>
            </div>
            <a href="/oauth/strava" class="btn-primary bg-orange-500 hover:bg-orange-600">
                Connecter Strava
            </a>
        </div>

        <!-- Stats -->
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
            <StatCard label="Distance cette semaine" :value="formatDistance(lastWeekStats.distance)" />
            <StatCard label="Temps de mouvement" :value="formatDuration(lastWeekStats.time)" />
            <StatCard label="Sorties" :value="String(lastWeekStats.count)" />
            <StatCard label="Plan actuel" :value="isPro ? 'Pro' : 'Free'" />
        </div>

        <!-- Weekly volume chart -->
        <div class="card mb-8">
            <h2 class="text-base font-medium text-gray-900 mb-4">Volume hebdomadaire (12 semaines)</h2>
            <WeeklyVolumeChart :data="weeklyVolume" />
        </div>

        <!-- Recent activities -->
        <div class="card">
            <h2 class="text-base font-medium text-gray-900 mb-4">Activités récentes</h2>
            <div v-if="recentActivities?.length === 0" class="text-sm text-gray-400 text-center py-8">
                Aucune activité. Connecte Strava pour importer tes courses.
            </div>
            <div v-else class="divide-y divide-gray-100">
                <div v-for="activity in recentActivities" :key="activity.id"
                     class="flex items-center justify-between py-3">
                    <div>
                        <p class="text-sm font-medium text-gray-900">{{ activity.name }}</p>
                        <p class="text-xs text-gray-500">{{ activity.type }} · {{ new Date(activity.startDate).toLocaleDateString('fr-FR') }}</p>
                    </div>
                    <div class="text-right">
                        <p class="text-sm font-medium text-gray-900">{{ formatDistance(activity.distanceKm * 1000) }}</p>
                        <p class="text-xs text-gray-500">{{ formatDuration(activity.movingTime) }}</p>
                    </div>
                </div>
            </div>
        </div>

    </div>
</template>
