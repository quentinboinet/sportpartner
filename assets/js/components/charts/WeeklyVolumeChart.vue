<script setup>
import { computed } from 'vue'
import { Bar } from 'vue-chartjs'
import { Chart as ChartJS, BarElement, CategoryScale, LinearScale, Tooltip, Legend } from 'chart.js'
import { useUnits } from '@/composables/useUnits'

ChartJS.register(BarElement, CategoryScale, LinearScale, Tooltip, Legend)

const props = defineProps({
    data: { type: Array, default: () => [] },
})

const { system } = useUnits()

const chartData = computed(() => ({
    labels: props.data.map(w => `S${w.week}`),
    datasets: [{
        label: system.value === 'imperial' ? 'Distance (mi)' : 'Distance (km)',
        data: props.data.map(w => system.value === 'imperial'
            ? Math.round(w.totalDistance / 1609.344 * 10) / 10
            : Math.round(w.totalDistance / 1000 * 10) / 10
        ),
        backgroundColor: '#22c55e',
        borderRadius: 4,
    }],
}))

const options = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: { legend: { display: false } },
    scales: {
        y: { beginAtZero: true, grid: { color: '#f3f4f6' } },
        x: { grid: { display: false } },
    },
}
</script>

<template>
    <div style="height: 220px">
        <Bar :data="chartData" :options="options" />
    </div>
</template>
