import { Controller } from '@hotwired/stimulus';
import L from 'leaflet';

function decodePolyline(encoded) {
  const coords = [];
  let index = 0, lat = 0, lng = 0;
  while (index < encoded.length) {
    let b, shift = 0, result = 0;
    do { b = encoded.charCodeAt(index++) - 63; result |= (b & 0x1f) << shift; shift += 5; } while (b >= 0x20);
    lat += (result & 1) ? ~(result >> 1) : (result >> 1);
    shift = 0; result = 0;
    do { b = encoded.charCodeAt(index++) - 63; result |= (b & 0x1f) << shift; shift += 5; } while (b >= 0x20);
    lng += (result & 1) ? ~(result >> 1) : (result >> 1);
    coords.push([lat / 1e5, lng / 1e5]);
  }
  return coords;
}

export default class extends Controller {
  static values = { polyline: String };

  connect() {
    const coords = decodePolyline(this.polylineValue);
    if (!coords.length) return;

    const map = L.map(this.element, { zoomControl: true, attributionControl: false });
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19,
    }).addTo(map);

    const route = L.polyline(coords, { color: '#E8400C', weight: 3, opacity: 0.85 }).addTo(map);
    map.fitBounds(route.getBounds(), { padding: [16, 16] });

    const startIcon = L.divIcon({ className: '', html: '<div class="sp-map-dot sp-map-dot-start"></div>', iconSize: [12, 12], iconAnchor: [6, 6] });
    const endIcon   = L.divIcon({ className: '', html: '<div class="sp-map-dot sp-map-dot-end"></div>',   iconSize: [12, 12], iconAnchor: [6, 6] });
    L.marker(coords[0], { icon: startIcon }).addTo(map);
    L.marker(coords[coords.length - 1], { icon: endIcon }).addTo(map);

    // ── Synchronisation avec le graphe d'élévation ────────────────────────────
    let posMarker = null;

    this._onElevationHover = ({ detail: { t } }) => {
      const idx = Math.round(t * (coords.length - 1));
      const pos = coords[idx];
      if (posMarker) {
        posMarker.setLatLng(pos);
      } else {
        posMarker = L.circleMarker(pos, {
          radius: 7,
          color: '#fff',
          fillColor: '#E8400C',
          fillOpacity: 1,
          weight: 2.5,
        }).addTo(map);
      }
    };

    this._onElevationLeave = () => {
      if (posMarker) { posMarker.remove(); posMarker = null; }
    };

    window.addEventListener('elevation:hover', this._onElevationHover);
    window.addEventListener('elevation:leave', this._onElevationLeave);
  }

  disconnect() {
    window.removeEventListener('elevation:hover', this._onElevationHover);
    window.removeEventListener('elevation:leave', this._onElevationLeave);
  }
}
