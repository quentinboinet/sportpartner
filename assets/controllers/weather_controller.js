import { Controller } from '@hotwired/stimulus';
import { Modal } from 'bootstrap';
import { WMO_ICONS, WMO_LABELS, DAY_NAMES } from './weather_constants.js';

export default class extends Controller {
  static targets = ['loading', 'error', 'errorMsg', 'content', 'icon', 'temp', 'desc', 'wind', 'city'];
  static values  = { locale: { type: String, default: 'fr' } };

  #loaded = false;

  connect() {
    if (!navigator.geolocation) { this.#showError(); return; }
    this.#requestPosition();
  }

  retry() {
    this.errorTarget.hidden   = true;
    this.loadingTarget.hidden = false;
    this.#requestPosition();
  }

  openForecast() {
    if (!this.#loaded) return;
    new Modal(document.getElementById('weatherModal')).show();
  }

  #requestPosition() {
    navigator.geolocation.getCurrentPosition(
      pos => this.#fetchWeather(pos.coords.latitude, pos.coords.longitude),
      err => this.#showError(err.code === 1 ? 'denied' : 'unavailable'),
    );
  }

  async #fetchWeather(lat, lon) {
    try {
      const wxUrl  = `https://api.open-meteo.com/v1/forecast?latitude=${lat}&longitude=${lon}`
        + `&current=temperature_2m,weather_code,wind_speed_10m`
        + `&hourly=precipitation&forecast_hours=48`
        + `&daily=weather_code,temperature_2m_max,temperature_2m_min,precipitation_sum`
        + `&timezone=auto&forecast_days=7`;
      const geoUrl = `https://nominatim.openstreetmap.org/reverse?lat=${lat}&lon=${lon}&format=json`;

      const [wx, geo] = await Promise.all([
        fetch(wxUrl).then(r => r.json()),
        fetch(geoUrl).then(r => r.json()),
      ]);

      const cur    = wx.current;
      const locale = this.localeValue;
      const icon   = WMO_ICONS[cur.weather_code] ?? 'bi-cloud';
      const label  = (WMO_LABELS[locale] ?? WMO_LABELS.fr)[cur.weather_code] ?? '';
      const city   = geo.address?.city ?? geo.address?.town ?? geo.address?.village ?? '';

      this.iconTarget.className   = `bi ${icon} sp-weather-icon`;
      this.tempTarget.textContent = `${Math.round(cur.temperature_2m)}°`;
      this.descTarget.textContent = label;
      this.windTarget.textContent = Math.round(cur.wind_speed_10m);
      this.cityTarget.textContent = city;

      this.#renderForecast(wx.daily, locale);
      this.#renderPrecip(wx.hourly, locale);

      this.loadingTarget.hidden = true;
      this.contentTarget.hidden = false;
      this.#loaded = true;
    } catch (e) {
      console.error('[weather]', e);
      this.#showError();
    }
  }

  #renderForecast(daily, locale) {
    const forecastEl = document.querySelector('[data-weather-forecast]');
    if (!forecastEl) return;

    const days   = DAY_NAMES[locale]  ?? DAY_NAMES.fr;
    const labels = WMO_LABELS[locale] ?? WMO_LABELS.fr;

    forecastEl.innerHTML = daily.time.map((d, i) => {
      const day  = days[new Date(d).getDay()];
      const icon = WMO_ICONS[daily.weather_code[i]] ?? 'bi-cloud';
      const max  = Math.round(daily.temperature_2m_max[i]);
      const min  = Math.round(daily.temperature_2m_min[i]);
      const rain = daily.precipitation_sum[i];
      const desc = labels[daily.weather_code[i]] ?? '';

      return `<div class="sp-forecast-row">
        <span class="sp-forecast-day">${day}</span>
        <i class="bi ${icon} sp-forecast-icon"></i>
        <span class="sp-forecast-desc">${desc}</span>
        <span class="sp-forecast-temp">${max}°&nbsp;<span class="sp-forecast-min">${min}°</span></span>
        ${rain > 0 ? `<span class="sp-forecast-rain"><i class="bi bi-droplet-fill"></i> ${rain}mm</span>` : ''}
      </div>`;
    }).join('');
  }

  #renderPrecip(hourly, locale) {
    const precipEl = document.querySelector('[data-weather-precip]');
    if (!precipEl) return;

    const now = Date.now();
    const items = hourly.time
      .map((t, i) => ({ time: new Date(t), mm: hourly.precipitation[i] ?? 0 }))
      .filter(({ time }) => time.getTime() >= now - 1800000)
      .slice(0, 24);

    if (!items.length) return;

    const total = items.reduce((s, { mm }) => s + mm, 0);
    const maxMm = Math.max(...items.map(({ mm }) => mm), 0.1);
    const titleLabel  = locale === 'fr' ? 'Précipitations 24h' : '24h Precipitation';
    const noRainLabel = locale === 'fr' ? 'Aucune précipitation prévue' : 'No precipitation expected';

    const bars = items.map(({ time, mm }, i) => {
      const h         = time.getHours();
      const showLabel = i === 0 || h % 6 === 0;
      const pct       = mm > 0 ? Math.max((mm / maxMm) * 100, 5) : 0;
      const title     = mm > 0 ? `${h}h – ${mm.toFixed(1)} mm` : `${h}h`;
      return `<div class="sp-precip-col" title="${title}">
        <div class="sp-precip-bar-wrap">
          <div class="sp-precip-bar" style="height:${pct}%"></div>
        </div>
        <span class="sp-precip-lbl">${showLabel ? h + 'h' : ''}</span>
      </div>`;
    }).join('');

    precipEl.innerHTML = `
      <div class="sp-precip-header">
        <span class="sp-precip-title"><i class="bi bi-droplet-half"></i> ${titleLabel}</span>
        ${total > 0 ? `<span class="sp-precip-total">${total.toFixed(1)} mm</span>` : ''}
      </div>
      ${total > 0
        ? `<div class="sp-precip-chart">${bars}</div>`
        : `<p class="sp-precip-none">${noRainLabel}</p>`
      }
    `;
  }


  #showError(reason = 'denied') {
    this.loadingTarget.hidden = true;
    this.errorTarget.hidden   = false;
    if (this.hasErrorMsgTarget) {
      this.errorMsgTarget.textContent = reason === 'denied'
        ? this.element.dataset.weatherDeniedLabel ?? 'Géolocalisation refusée'
        : 'Position indisponible';
    }
  }
}
