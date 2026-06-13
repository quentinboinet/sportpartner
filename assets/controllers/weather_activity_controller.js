import { Controller } from '@hotwired/stimulus';
import { WMO_ICONS, WMO_LABELS } from './weather_constants.js';

export default class extends Controller {
  static targets = ['content', 'icon', 'temp', 'desc', 'wind', 'precip'];
  static values  = {
    lat:    Number,
    lon:    Number,
    date:   String,
    hour:   Number,
    locale: { type: String, default: 'fr' },
  };

  connect() {
    if (!this.dateValue || !this.latValue || !this.lonValue) return;
    this.#fetch();
  }

  async #fetch() {
    try {
      const today     = new Date().toISOString().slice(0, 10);
      const yesterday = new Date(Date.now() - 86400000).toISOString().slice(0, 10);

      let url;
      if (this.dateValue >= today) {
        // Activité du jour : données en temps réel (heure = heure courante approximative)
        url = `https://api.open-meteo.com/v1/forecast?latitude=${this.latValue}&longitude=${this.lonValue}`
          + `&hourly=temperature_2m,precipitation,weather_code,wind_speed_10m`
          + `&timezone=auto&forecast_days=1`;
      } else if (this.dateValue >= yesterday) {
        // Hier : forecast API avec past_days=1
        url = `https://api.open-meteo.com/v1/forecast?latitude=${this.latValue}&longitude=${this.lonValue}`
          + `&hourly=temperature_2m,precipitation,weather_code,wind_speed_10m`
          + `&timezone=auto&past_days=1&forecast_days=1`;
      } else {
        // Historique : archive ERA5
        url = `https://archive.open-meteo.com/v1/archive?latitude=${this.latValue}&longitude=${this.lonValue}`
          + `&start_date=${this.dateValue}&end_date=${this.dateValue}`
          + `&hourly=temperature_2m,precipitation,weather_code,wind_speed_10m`
          + `&timezone=auto`;
      }

      const data   = await fetch(url).then(r => r.json());
      const hourly = data.hourly;
      if (!hourly) return;

      // Cherche l'index correspondant à l'heure de départ de l'activité
      const idx = hourly.time.findIndex(t => new Date(t).getHours() === this.hourValue
        && new Date(t).toISOString().slice(0, 10) === this.dateValue);
      if (idx === -1) return;

      const temp   = Math.round(hourly.temperature_2m[idx]);
      const code   = hourly.weather_code[idx];
      const precip = hourly.precipitation[idx] ?? 0;
      const wind   = Math.round(hourly.wind_speed_10m[idx]);
      const icon   = WMO_ICONS[code] ?? 'bi-cloud';
      const label  = (WMO_LABELS[this.localeValue] ?? WMO_LABELS.fr)[code] ?? '';

      this.iconTarget.className   = `bi ${icon} sp-act-wx-icon`;
      this.tempTarget.textContent = `${temp}°C`;
      this.descTarget.textContent = label;
      this.windTarget.textContent = `${wind} km/h`;

      if (precip > 0) {
        this.precipTarget.textContent = `· ${precip.toFixed(1)} mm`;
        this.precipTarget.hidden = false;
      }

      this.contentTarget.hidden = false;
    } catch (e) {
      console.warn('[weather-activity]', e);
    }
  }
}
