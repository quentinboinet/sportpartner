import { application } from './application.js';
import WeeklyChartController    from './weekly_chart_controller.js';
import ElevationChartController from './elevation_chart_controller.js';
import PaceChartController      from './pace_chart_controller.js';
import VolumeChartController    from './volume_chart_controller.js';
import DonutChartController     from './donut_chart_controller.js';
import PaceTrendController      from './pace_trend_controller.js';
import EnergyDonutController    from './energy_donut_controller.js';
import BalanceChartController   from './balance_chart_controller.js';

application.register('weekly-chart',    WeeklyChartController);
application.register('elevation-chart', ElevationChartController);
application.register('pace-chart',      PaceChartController);
application.register('volume-chart',    VolumeChartController);
application.register('donut-chart',     DonutChartController);
application.register('pace-trend',      PaceTrendController);
application.register('energy-donut',    EnergyDonutController);
application.register('balance-chart',   BalanceChartController);
