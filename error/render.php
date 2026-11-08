<?php
/**
 * Renderer Halaman Error Visual Premium, Beragam & Terproteksi
 * Manajemen-PHP — Sistem Terpadu Manajemen Sekolah
 * 
 * Fitur Utama:
 * - Desain Estetik & Berbeda-beda untuk Setiap Kode Error (Bespoke SVG Artwork & Layout)
 * - Tampilan Responsif (Mobile, Tablet, Desktop)
 * - Anti XSS & Content Spoofing melalui sanitasi ketat
 * - Security Headers Komprehensif (CSP, X-Frame-Options, X-Content-Type-Options)
 * - Fitur Interaktif: Salin ID Tiket, Uji Koneksi Jaringan, Hitung Mundur Otomatis, Pencarian Cepat
 * - Zero Session-ID Leakage (Cryptographic Incident Ticket)
 * - Dynamic Base Path Resolution (Anti broken-links pada nested routes)
 */

// Cegah pemanggilan berkas secara langsung demi keamanan
if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(403);
    header("X-Content-Type-Options: nosniff");
    header("X-Frame-Options: DENY");
    exit('Akses langsung ke berkas ini dibatasi demi keamanan.');
}

require_once __DIR__ . "/error_data.php";

/**
 * Helper pembuat ilustrasi SVG tematik sesuai arketipe error
 */
function renderErrorSvg(string $theme, string $accent, int $code): string {
    switch ($theme) {
        case 'cosmic': // 404 Not Found
            return '
            <svg class="w-full h-full" viewBox="0 0 320 280" fill="none" xmlns="http://www.w3.org/2000/svg">
                <defs>
                    <radialGradient id="cosmicGlow" cx="50%" cy="50%" r="50%">
                        <stop offset="0%" stop-color="#a855f7" stop-opacity="0.35"/>
                        <stop offset="100%" stop-color="#a855f7" stop-opacity="0"/>
                    </radialGradient>
                    <linearGradient id="planetGrad" x1="0%" y1="0%" x2="100%" y2="100%">
                        <stop offset="0%" stop-color="#c084fc"/>
                        <stop offset="50%" stop-color="#7c3aed"/>
                        <stop offset="100%" stop-color="#3b0764"/>
                    </linearGradient>
                    <linearGradient id="suitGrad" x1="0%" y1="0%" x2="100%" y2="100%">
                        <stop offset="0%" stop-color="#ffffff"/>
                        <stop offset="100%" stop-color="#94a3b8"/>
                    </linearGradient>
                    <linearGradient id="visorGrad" x1="0%" y1="0%" x2="100%" y2="100%">
                        <stop offset="0%" stop-color="#38bdf8"/>
                        <stop offset="100%" stop-color="#0284c7"/>
                    </linearGradient>
                </defs>
                <!-- Nebula Aura -->
                <circle cx="160" cy="140" r="120" fill="url(#cosmicGlow)"/>
                <!-- Background Stars -->
                <circle cx="45" cy="50" r="2" fill="#e2e8f0" class="animate-ping" style="animation-duration: 3s;"/>
                <circle cx="280" cy="70" r="2.5" fill="#f8fafc" class="animate-ping" style="animation-duration: 4s;"/>
                <circle cx="70" cy="210" r="1.5" fill="#cbd5e1" class="animate-ping" style="animation-duration: 2.5s;"/>
                <circle cx="270" cy="220" r="2" fill="#c084fc" class="animate-ping" style="animation-duration: 3.5s;"/>
                <circle cx="130" cy="30" r="1" fill="#ffffff"/>
                <circle cx="210" cy="40" r="1.5" fill="#a855f7"/>
                
                <!-- Orbit Ring -->
                <ellipse cx="160" cy="140" rx="130" ry="45" stroke="#a855f7" stroke-opacity="0.3" stroke-width="2" stroke-dasharray="6 6" transform="rotate(-18 160 140)"/>
                
                <!-- Floating Small Planet -->
                <circle cx="65" cy="80" r="18" fill="url(#planetGrad)" filter="drop-shadow(0 0 12px rgba(168, 85, 247, 0.5))"/>
                <ellipse cx="65" cy="80" rx="26" ry="7" stroke="#e9d5ff" stroke-width="1.5" stroke-opacity="0.6" transform="rotate(25 65 80)"/>

                <!-- Astronaut Character (Floating) -->
                <g class="float-anim">
                    <!-- Tether Cable -->
                    <path d="M120 180 Q90 220 50 240" stroke="#94a3b8" stroke-width="2.5" stroke-dasharray="3 3" stroke-linecap="round"/>
                    
                    <!-- Backpack -->
                    <rect x="135" y="105" width="50" height="60" rx="14" fill="#64748b" stroke="#cbd5e1" stroke-width="2"/>
                    
                    <!-- Body / Spacesuit -->
                    <rect x="142" y="115" width="36" height="48" rx="12" fill="url(#suitGrad)" stroke="#e2e8f0" stroke-width="2"/>
                    <!-- Chest badge -->
                    <rect x="150" y="125" width="20" height="12" rx="3" fill="#0f172a"/>
                    <circle cx="156" cy="131" r="2" fill="#38bdf8"/>
                    <circle cx="164" cy="131" r="2" fill="#a855f7"/>

                    <!-- Helmet -->
                    <circle cx="160" cy="95" r="26" fill="url(#suitGrad)" stroke="#e2e8f0" stroke-width="2.5"/>
                    <!-- Helmet Visor -->
                    <ellipse cx="162" cy="96" rx="17" ry="14" fill="url(#visorGrad)" stroke="#0284c7" stroke-width="1.5"/>
                    <!-- Visor Reflection -->
                    <path d="M152 90 Q160 85 168 89" stroke="#ffffff" stroke-width="2" stroke-linecap="round" stroke-opacity="0.8"/>
                    
                    <!-- Floating Arms -->
                    <path d="M142 125 C125 130 120 145 125 155" stroke="url(#suitGrad)" stroke-width="8" stroke-linecap="round"/>
                    <path d="M178 125 C195 120 205 130 200 142" stroke="url(#suitGrad)" stroke-width="8" stroke-linecap="round"/>
                    
                    <!-- Floating Legs -->
                    <path d="M150 160 C145 180 142 195 138 205" stroke="url(#suitGrad)" stroke-width="9" stroke-linecap="round"/>
                    <path d="M170 160 C175 180 180 195 185 205" stroke="url(#suitGrad)" stroke-width="9" stroke-linecap="round"/>
                </g>
                
                <!-- Tiny Satellite -->
                <g transform="translate(240, 160) rotate(15)">
                    <rect x="-8" y="-8" width="16" height="16" rx="3" fill="#cbd5e1" stroke="#475569"/>
                    <line x1="-22" y1="0" x2="-8" y2="0" stroke="#38bdf8" stroke-width="4"/>
                    <line x1="8" y1="0" x2="22" y2="0" stroke="#38bdf8" stroke-width="4"/>
                    <circle cx="0" cy="0" r="2" fill="#ef4444"/>
                </g>
            </svg>';

        case 'security': // 403 Forbidden
            return '
            <svg class="w-full h-full" viewBox="0 0 320 280" fill="none" xmlns="http://www.w3.org/2000/svg">
                <defs>
                    <radialGradient id="secGlow" cx="50%" cy="50%" r="50%">
                        <stop offset="0%" stop-color="#f43f5e" stop-opacity="0.35"/>
                        <stop offset="100%" stop-color="#f43f5e" stop-opacity="0"/>
                    </radialGradient>
                    <linearGradient id="shieldGrad" x1="0%" y1="0%" x2="100%" y2="100%">
                        <stop offset="0%" stop-color="#fb7185"/>
                        <stop offset="50%" stop-color="#e11d48"/>
                        <stop offset="100%" stop-color="#881337"/>
                    </linearGradient>
                    <linearGradient id="laserGrad" x1="0%" y1="0%" x2="100%" y2="0%">
                        <stop offset="0%" stop-color="transparent"/>
                        <stop offset="50%" stop-color="#f43f5e"/>
                        <stop offset="100%" stop-color="transparent"/>
                    </linearGradient>
                </defs>
                <!-- Red Security Aura -->
                <circle cx="160" cy="140" r="115" fill="url(#secGlow)"/>

                <!-- Cyber Reticle Rings -->
                <circle cx="160" cy="140" r="100" stroke="#f43f5e" stroke-opacity="0.25" stroke-width="1.5" stroke-dasharray="10 8"/>
                <circle cx="160" cy="140" r="80" stroke="#f43f5e" stroke-opacity="0.35" stroke-width="1.5" stroke-dasharray="4 4"/>
                
                <!-- Target Brackets -->
                <path d="M60 90 L60 60 L90 60" stroke="#f43f5e" stroke-width="2.5" stroke-linecap="round"/>
                <path d="M260 90 L260 60 L230 60" stroke="#f43f5e" stroke-width="2.5" stroke-linecap="round"/>
                <path d="M60 190 L60 220 L90 220" stroke="#f43f5e" stroke-width="2.5" stroke-linecap="round"/>
                <path d="M260 190 L260 220 L230 220" stroke="#f43f5e" stroke-width="2.5" stroke-linecap="round"/>

                <!-- High-Tech Holographic Shield (Central) -->
                <g class="float-anim">
                    <!-- Outer Hex Shield -->
                    <path d="M160 55 L225 88 V155 C225 195 160 225 160 225 C160 225 95 195 95 155 V88 Z" 
                          fill="url(#shieldGrad)" stroke="#fecdd3" stroke-width="3" filter="drop-shadow(0 0 20px rgba(244,63,94,0.5))"/>
                    
                    <!-- Inner Hex Accent -->
                    <path d="M160 70 L210 96 V150 C210 180 160 205 160 205 C160 205 110 180 110 150 V96 Z" 
                          fill="#0f172a" fill-opacity="0.75" stroke="#f43f5e" stroke-width="1.5"/>

                    <!-- Biometric Padlock Visual -->
                    <!-- Padlock Shackle -->
                    <path d="M146 125 V110 C146 102 152 96 160 96 C168 96 174 102 174 110 V125" 
                          stroke="#ffffff" stroke-width="4" stroke-linecap="round" fill="none"/>
                    <!-- Padlock Body -->
                    <rect x="138" y="125" width="44" height="34" rx="8" fill="#e11d48" stroke="#ffffff" stroke-width="2"/>
                    <!-- Keyhole / Fingerprint Ring -->
                    <circle cx="160" cy="140" r="5" fill="#ffffff"/>
                    <path d="M160 145 V151" stroke="#ffffff" stroke-width="3" stroke-linecap="round"/>
                    
                    <!-- Horizontal Scanning Laser Beam -->
                    <rect x="80" y="130" width="160" height="3" fill="url(#laserGrad)" class="scan-laser-anim"/>
                </g>
                
                <!-- Status Code Text -->
                <text x="160" y="260" font-family="Plus Jakarta Sans, sans-serif" font-weight="900" font-size="14" fill="#fb7185" text-anchor="middle" letter-spacing="3">
                    [ ACCESS RESTRICTED // CLEARANCE REQUIRED ]
                </text>
            </svg>';

        case 'server_crash': // 500 Internal Server Error
            return '
            <svg class="w-full h-full" viewBox="0 0 320 280" fill="none" xmlns="http://www.w3.org/2000/svg">
                <defs>
                    <radialGradient id="serverGlow" cx="50%" cy="50%" r="50%">
                        <stop offset="0%" stop-color="#f59e0b" stop-opacity="0.35"/>
                        <stop offset="100%" stop-color="#ef4444" stop-opacity="0"/>
                    </radialGradient>
                    <linearGradient id="rackGrad" x1="0%" y1="0%" x2="100%" y2="100%">
                        <stop offset="0%" stop-color="#1e293b"/>
                        <stop offset="100%" stop-color="#0f172a"/>
                    </linearGradient>
                </defs>
                <!-- Anomaly Glow -->
                <circle cx="160" cy="140" r="115" fill="url(#serverGlow)"/>

                <!-- Server Rack Main Tower -->
                <g class="float-anim">
                    <!-- Rack Frame -->
                    <rect x="95" y="45" width="130" height="180" rx="16" fill="url(#rackGrad)" stroke="#f59e0b" stroke-width="2.5" filter="drop-shadow(0 0 25px rgba(245,158,11,0.3))"/>
                    
                    <!-- Server Unit 1 -->
                    <rect x="107" y="60" width="106" height="30" rx="6" fill="#334155" stroke="#475569" stroke-width="1"/>
                    <circle cx="120" cy="75" r="3" fill="#10b981"/>
                    <circle cx="130" cy="75" r="3" fill="#3b82f6"/>
                    <rect x="145" y="73" width="55" height="4" rx="2" fill="#64748b"/>

                    <!-- Server Unit 2 (Crashing / Sparking Unit) -->
                    <rect x="107" y="100" width="106" height="34" rx="6" fill="#450a0a" stroke="#ef4444" stroke-width="1.5"/>
                    <circle cx="120" cy="117" r="4" fill="#ef4444" class="animate-ping"/>
                    <circle cx="132" cy="117" r="3" fill="#f59e0b"/>
                    <!-- Warning Matrix -->
                    <rect x="145" y="113" width="55" height="8" rx="2" fill="#7f1d1d"/>
                    
                    <!-- Server Unit 3 -->
                    <rect x="107" y="144" width="106" height="30" rx="6" fill="#334155" stroke="#475569" stroke-width="1"/>
                    <circle cx="120" cy="159" r="3" fill="#10b981"/>
                    <circle cx="130" cy="159" r="3" fill="#64748b"/>
                    <rect x="145" y="157" width="55" height="4" rx="2" fill="#64748b"/>

                    <!-- Server Unit 4 (Power Hub) -->
                    <rect x="107" y="184" width="106" height="26" rx="6" fill="#1e293b" stroke="#334155" stroke-width="1"/>
                    <line x1="115" y1="197" x2="205" y2="197" stroke="#475569" stroke-width="2" stroke-dasharray="4 3"/>

                    <!-- Severed Cable & Spark Particles -->
                    <path d="M70 120 Q90 125 107 115" stroke="#ef4444" stroke-width="3" stroke-linecap="round"/>
                    <path d="M213 115 Q235 110 250 135" stroke="#f59e0b" stroke-width="3" stroke-linecap="round"/>
                    
                    <!-- Spark Bursts -->
                    <polygon points="65,115 72,118 70,123 75,121 73,127 66,122" fill="#fbbf24"/>
                    <polygon points="252,130 258,135 255,140 262,138 259,145 250,138" fill="#ef4444"/>
                    
                    <!-- Warning Hazard Triangle -->
                    <g transform="translate(142, 16) scale(0.9)">
                        <polygon points="20,5 38,35 2,35" fill="#f59e0b" stroke="#ffffff" stroke-width="2"/>
                        <text x="20" y="28" font-family="sans-serif" font-weight="900" font-size="16" fill="#0f172a" text-anchor="middle">!</text>
                    </g>
                </g>
            </svg>';

        case 'maintenance': // 503 Service Unavailable
            return '
            <svg class="w-full h-full" viewBox="0 0 320 280" fill="none" xmlns="http://www.w3.org/2000/svg">
                <defs>
                    <radialGradient id="maintGlow" cx="50%" cy="50%" r="50%">
                        <stop offset="0%" stop-color="#10b981" stop-opacity="0.35"/>
                        <stop offset="100%" stop-color="#06b6d4" stop-opacity="0"/>
                    </radialGradient>
                    <linearGradient id="gearGrad" x1="0%" y1="0%" x2="100%" y2="100%">
                        <stop offset="0%" stop-color="#34d399"/>
                        <stop offset="100%" stop-color="#059669"/>
                    </linearGradient>
                    <linearGradient id="gear2Grad" x1="0%" y1="0%" x2="100%" y2="100%">
                        <stop offset="0%" stop-color="#22d3ee"/>
                        <stop offset="100%" stop-color="#0891b2"/>
                    </linearGradient>
                </defs>
                <circle cx="160" cy="140" r="115" fill="url(#maintGlow)"/>

                <!-- Blueprint Grid Lines -->
                <line x1="60" y1="80" x2="260" y2="80" stroke="#10b981" stroke-opacity="0.2" stroke-dasharray="4 4"/>
                <line x1="60" y1="140" x2="260" y2="140" stroke="#10b981" stroke-opacity="0.2" stroke-dasharray="4 4"/>
                <line x1="60" y1="200" x2="260" y2="200" stroke="#10b981" stroke-opacity="0.2" stroke-dasharray="4 4"/>
                <line x1="110" y1="40" x2="110" y2="240" stroke="#10b981" stroke-opacity="0.2" stroke-dasharray="4 4"/>
                <line x1="210" y1="40" x2="210" y2="240" stroke="#10b981" stroke-opacity="0.2" stroke-dasharray="4 4"/>

                <!-- Primary Large Rotating Gear -->
                <g class="spin-gear" style="transform-origin: 135px 125px;">
                    <circle cx="135" cy="125" r="42" fill="url(#gearGrad)" stroke="#a7f3d0" stroke-width="2.5" filter="drop-shadow(0 0 15px rgba(16,185,129,0.4))"/>
                    <!-- Gear Teeth -->
                    <path d="M130 75 H140 V86 H130 Z M130 164 H140 V175 H130 Z M85 120 H96 V130 H85 Z M174 120 H185 V130 H174 Z
                             M100 90 L108 97 L101 104 L93 97 Z M169 146 L177 153 L170 160 L162 153 Z
                             M162 97 L170 90 L177 97 L169 104 Z M93 153 L101 146 L108 153 L100 160 Z" fill="#34d399"/>
                    <!-- Inner Cutout -->
                    <circle cx="135" cy="125" r="18" fill="#0f172a" stroke="#6ee7b7" stroke-width="2"/>
                    <circle cx="135" cy="125" r="6" fill="#10b981"/>
                </g>

                <!-- Secondary Interlocking Counter-Rotating Gear -->
                <g class="spin-gear-reverse" style="transform-origin: 200px 175px;">
                    <circle cx="200" cy="175" r="30" fill="url(#gear2Grad)" stroke="#a5f3fc" stroke-width="2" filter="drop-shadow(0 0 12px rgba(6,182,212,0.4))"/>
                    <!-- Teeth -->
                    <path d="M196 140 H204 V148 H196 Z M196 202 H204 V210 H196 Z M165 171 H173 V179 H165 Z M227 171 H235 V179 H227 Z" fill="#22d3ee"/>
                    <circle cx="200" cy="175" r="12" fill="#0f172a" stroke="#67e8f9" stroke-width="1.5"/>
                    <circle cx="200" cy="175" r="4" fill="#06b6d4"/>
                </g>

                <!-- Heartbeat Telemetry Line at Bottom -->
                <path d="M60 230 H110 L120 215 L130 245 L145 205 L155 240 L165 230 H260" 
                      stroke="#10b981" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" filter="drop-shadow(0 0 8px #10b981)"/>
            </svg>';

        case 'auth_gate': // 401 Unauthorized
            return '
            <svg class="w-full h-full" viewBox="0 0 320 280" fill="none" xmlns="http://www.w3.org/2000/svg">
                <defs>
                    <radialGradient id="authGlow" cx="50%" cy="50%" r="50%">
                        <stop offset="0%" stop-color="#0284c7" stop-opacity="0.35"/>
                        <stop offset="100%" stop-color="#3b82f6" stop-opacity="0"/>
                    </radialGradient>
                    <linearGradient id="cardGrad" x1="0%" y1="0%" x2="100%" y2="100%">
                        <stop offset="0%" stop-color="#0369a1"/>
                        <stop offset="60%" stop-color="#0f172a"/>
                        <stop offset="100%" stop-color="#1e293b"/>
                    </linearGradient>
                </defs>
                <circle cx="160" cy="140" r="115" fill="url(#authGlow)"/>

                <!-- Hologram Grid Base -->
                <ellipse cx="160" cy="220" rx="90" ry="25" stroke="#38bdf8" stroke-opacity="0.35" stroke-width="1.5" stroke-dasharray="6 4"/>
                <ellipse cx="160" cy="220" rx="60" ry="16" stroke="#38bdf8" stroke-opacity="0.5" stroke-width="1"/>

                <!-- Floating Holographic Smart ID Card -->
                <g class="float-anim">
                    <!-- ID Card Body -->
                    <rect x="75" y="65" width="170" height="110" rx="14" fill="url(#cardGrad)" stroke="#38bdf8" stroke-width="2.5" filter="drop-shadow(0 0 25px rgba(56,189,248,0.4))"/>
                    
                    <!-- Card Header Stripe -->
                    <rect x="76" y="66" width="168" height="24" rx="12" fill="#0284c7" fill-opacity="0.6"/>
                    <circle cx="95" cy="78" r="4" fill="#38bdf8"/>
                    <rect x="105" y="75" width="70" height="6" rx="3" fill="#e0f2fe"/>

                    <!-- Avatar Box -->
                    <rect x="92" y="102" width="40" height="48" rx="8" fill="#1e293b" stroke="#38bdf8" stroke-width="1.5"/>
                    <circle cx="112" cy="116" r="8" fill="#7dd3fc"/>
                    <path d="M98 142 C98 132 104 128 112 128 C120 128 126 132 126 142 Z" fill="#7dd3fc"/>

                    <!-- Smart Card Chip & RFID Waves -->
                    <rect x="145" y="105" width="22" height="18" rx="4" fill="#f59e0b" stroke="#fbbf24" stroke-width="1.5"/>
                    <line x1="145" y1="114" x2="167" y2="114" stroke="#78350f" stroke-width="1"/>
                    <line x1="156" y1="105" x2="156" y2="123" stroke="#78350f" stroke-width="1"/>

                    <!-- User Detail Lines -->
                    <rect x="175" y="106" width="55" height="5" rx="2.5" fill="#94a3b8"/>
                    <rect x="145" y="132" width="85" height="5" rx="2.5" fill="#64748b"/>
                    <rect x="145" y="143" width="60" height="5" rx="2.5" fill="#64748b"/>

                    <!-- Golden Key Floating Above Card -->
                    <g transform="translate(180, 50) rotate(-25)">
                        <circle cx="16" cy="16" r="10" fill="none" stroke="#fbbf24" stroke-width="4" filter="drop-shadow(0 0 10px #f59e0b)"/>
                        <path d="M26 16 H50 V24 H44 V16 H38 V24 H32 V16" stroke="#fbbf24" stroke-width="4" stroke-linecap="round"/>
                    </g>
                </g>
                
                <!-- Scanning Rays Below Card -->
                <line x1="110" y1="185" x2="130" y2="215" stroke="#38bdf8" stroke-width="1.5" stroke-dasharray="3 3"/>
                <line x1="160" y1="185" x2="160" y2="218" stroke="#38bdf8" stroke-width="2"/>
                <line x1="210" y1="185" x2="190" y2="215" stroke="#38bdf8" stroke-width="1.5" stroke-dasharray="3 3"/>
            </svg>';

        case 'cooldown': // 429 Too Many Requests
            return '
            <svg class="w-full h-full" viewBox="0 0 320 280" fill="none" xmlns="http://www.w3.org/2000/svg">
                <defs>
                    <radialGradient id="tachGlow" cx="50%" cy="50%" r="50%">
                        <stop offset="0%" stop-color="#f43f5e" stop-opacity="0.35"/>
                        <stop offset="100%" stop-color="#ea580c" stop-opacity="0"/>
                    </radialGradient>
                    <linearGradient id="arcGrad" x1="0%" y1="0%" x2="100%" y2="0%">
                        <stop offset="0%" stop-color="#10b981"/>
                        <stop offset="45%" stop-color="#f59e0b"/>
                        <stop offset="85%" stop-color="#ef4444"/>
                    </linearGradient>
                </defs>
                <circle cx="160" cy="150" r="115" fill="url(#tachGlow)"/>

                <!-- Gauge Main Body -->
                <path d="M60 170 A110 110 0 1 1 260 170" stroke="url(#arcGrad)" stroke-width="14" stroke-linecap="round" fill="none"/>
                
                <!-- Inner Track -->
                <path d="M80 165 A85 85 0 1 1 240 165" stroke="#334155" stroke-width="4" stroke-dasharray="6 6" fill="none"/>

                <!-- Red Zone Warning Zone Flare -->
                <path d="M210 90 A110 110 0 0 1 260 170" stroke="#f43f5e" stroke-width="18" stroke-linecap="round" fill="none" filter="drop-shadow(0 0 12px #f43f5e)"/>

                <!-- Tachometer Needle (Pulsing at Redline) -->
                <g class="float-anim" style="transform-origin: 160px 170px;">
                    <circle cx="160" cy="170" r="14" fill="#0f172a" stroke="#f43f5e" stroke-width="4"/>
                    <path d="M160 170 L235 110" stroke="#f43f5e" stroke-width="5" stroke-linecap="round" filter="drop-shadow(0 0 8px #f43f5e)"/>
                    <circle cx="160" cy="170" r="5" fill="#fecdd3"/>
                </g>

                <!-- Flame / Overheat Steam Particles -->
                <path d="M230 75 Q235 60 225 50" stroke="#fb923c" stroke-width="2.5" stroke-linecap="round" opacity="0.8"/>
                <path d="M245 85 Q255 70 248 55" stroke="#f87171" stroke-width="2" stroke-linecap="round" opacity="0.8"/>
                <path d="M220 95 Q225 80 215 70" stroke="#facc15" stroke-width="2" stroke-linecap="round" opacity="0.8"/>

                <!-- Center Digital Readout Box -->
                <rect x="125" y="195" width="70" height="28" rx="6" fill="#0f172a" stroke="#f43f5e" stroke-width="1.5"/>
                <text x="160" y="214" font-family="Plus Jakarta Sans, sans-serif" font-weight="900" font-size="12" fill="#fb7185" text-anchor="middle" letter-spacing="2">
                    MAX RPS
                </text>
            </svg>';

        case 'hourglass': // 419 Page Expired
            return '
            <svg class="w-full h-full" viewBox="0 0 320 280" fill="none" xmlns="http://www.w3.org/2000/svg">
                <defs>
                    <radialGradient id="hourGlow" cx="50%" cy="50%" r="50%">
                        <stop offset="0%" stop-color="#f59e0b" stop-opacity="0.35"/>
                        <stop offset="100%" stop-color="#a855f7" stop-opacity="0"/>
                    </radialGradient>
                    <linearGradient id="goldWood" x1="0%" y1="0%" x2="100%" y2="100%">
                        <stop offset="0%" stop-color="#fde68a"/>
                        <stop offset="50%" stop-color="#f59e0b"/>
                        <stop offset="100%" stop-color="#b45309"/>
                    </linearGradient>
                </defs>
                <circle cx="160" cy="140" r="115" fill="url(#hourGlow)"/>

                <!-- Orbiting Time Rings -->
                <ellipse cx="160" cy="140" rx="100" ry="35" stroke="#f59e0b" stroke-opacity="0.3" stroke-width="2" stroke-dasharray="6 6" transform="rotate(-20 160 140)"/>

                <!-- Hourglass Structure (Floating) -->
                <g class="float-anim">
                    <!-- Top & Bottom Plates -->
                    <rect x="110" y="45" width="100" height="12" rx="6" fill="url(#goldWood)" stroke="#fef3c7" stroke-width="1.5" filter="drop-shadow(0 0 10px rgba(245,158,11,0.5))"/>
                    <rect x="110" y="223" width="100" height="12" rx="6" fill="url(#goldWood)" stroke="#fef3c7" stroke-width="1.5" filter="drop-shadow(0 0 10px rgba(245,158,11,0.5))"/>
                    
                    <!-- Glass Body -->
                    <path d="M125 57 C125 110 152 135 156 140 C152 145 125 170 125 223 H195 C195 170 168 145 164 140 C168 135 195 110 195 57 Z" 
                          fill="#0f172a" fill-opacity="0.6" stroke="#fde68a" stroke-width="2.5"/>

                    <!-- Falling Sand (Top Chamber: Almost empty) -->
                    <path d="M135 105 C145 120 175 120 185 105 Z" fill="#fbbf24"/>
                    <!-- Sand Stream -->
                    <line x1="160" y1="135" x2="160" y2="190" stroke="#f59e0b" stroke-width="3" stroke-dasharray="4 2"/>
                    <!-- Falling Particles -->
                    <circle cx="160" cy="155" r="2" fill="#fef08a"/>
                    <circle cx="159" cy="170" r="1.5" fill="#fef08a"/>
                    <!-- Bottom Chamber: Accumulated Sand -->
                    <path d="M132 222 C135 190 185 190 188 222 Z" fill="#f59e0b"/>

                    <!-- Side Support Pillars -->
                    <line x1="118" y1="57" x2="118" y2="223" stroke="url(#goldWood)" stroke-width="4" stroke-linecap="round"/>
                    <line x1="202" y1="57" x2="202" y2="223" stroke="url(#goldWood)" stroke-width="4" stroke-linecap="round"/>
                </g>
            </svg>';

        case 'financial': // 402 Payment Required
            return '
            <svg class="w-full h-full" viewBox="0 0 320 280" fill="none" xmlns="http://www.w3.org/2000/svg">
                <defs>
                    <radialGradient id="finGlow" cx="50%" cy="50%" r="50%">
                        <stop offset="0%" stop-color="#10b981" stop-opacity="0.35"/>
                        <stop offset="100%" stop-color="#eab308" stop-opacity="0"/>
                    </radialGradient>
                    <linearGradient id="finCard" x1="0%" y1="0%" x2="100%" y2="100%">
                        <stop offset="0%" stop-color="#047857"/>
                        <stop offset="60%" stop-color="#064e3b"/>
                        <stop offset="100%" stop-color="#022c22"/>
                    </linearGradient>
                </defs>
                <circle cx="160" cy="140" r="115" fill="url(#finGlow)"/>

                <g class="float-anim">
                    <!-- Background Invoice Sheet -->
                    <rect x="75" y="45" width="130" height="170" rx="12" fill="#1e293b" stroke="#334155" stroke-width="2"/>
                    <rect x="90" y="65" width="60" height="8" rx="4" fill="#475569"/>
                    <line x1="90" y1="90" x2="185" y2="90" stroke="#334155" stroke-width="2"/>
                    <line x1="90" y1="110" x2="165" y2="110" stroke="#334155" stroke-width="2"/>
                    <line x1="90" y1="130" x2="175" y2="130" stroke="#334155" stroke-width="2"/>

                    <!-- Main Credit/Tuition Card Overlapping -->
                    <rect x="105" y="85" width="150" height="96" rx="14" fill="url(#finCard)" stroke="#34d399" stroke-width="2.5" filter="drop-shadow(0 0 20px rgba(16,185,129,0.4))"/>
                    
                    <!-- EMV Chip -->
                    <rect x="125" y="110" width="22" height="18" rx="4" fill="#fbbf24" stroke="#d97706" stroke-width="1.5"/>
                    <line x1="125" y1="119" x2="147" y2="119" stroke="#92400e" stroke-width="1"/>
                    
                    <!-- Card Number Dots -->
                    <circle cx="128" cy="148" r="2.5" fill="#a7f3d0"/>
                    <circle cx="138" cy="148" r="2.5" fill="#a7f3d0"/>
                    <circle cx="148" cy="148" r="2.5" fill="#a7f3d0"/>
                    <circle cx="158" cy="148" r="2.5" fill="#a7f3d0"/>
                    <rect x="180" y="145" width="45" height="6" rx="3" fill="#6ee7b7"/>

                    <!-- Verified Currency Seal -->
                    <g transform="translate(195, 160)">
                        <circle cx="25" cy="25" r="25" fill="#eab308" stroke="#fef08a" stroke-width="3" filter="drop-shadow(0 0 10px #eab308)"/>
                        <text x="25" y="33" font-family="Plus Jakarta Sans, sans-serif" font-weight="900" font-size="22" fill="#78350f" text-anchor="middle">Rp</text>
                    </g>
                </g>
            </svg>';

        case 'timeout': // 408 & 504 Timeout
            return '
            <svg class="w-full h-full" viewBox="0 0 320 280" fill="none" xmlns="http://www.w3.org/2000/svg">
                <defs>
                    <radialGradient id="timeGlow" cx="50%" cy="50%" r="50%">
                        <stop offset="0%" stop-color="#eab308" stop-opacity="0.35"/>
                        <stop offset="100%" stop-color="#f97316" stop-opacity="0"/>
                    </radialGradient>
                </defs>
                <circle cx="160" cy="140" r="115" fill="url(#timeGlow)"/>

                <!-- Concentric Radar Echo Waves -->
                <circle cx="160" cy="170" r="40" stroke="#facc15" stroke-opacity="0.6" stroke-width="2" class="radar-ping"/>
                <circle cx="160" cy="170" r="75" stroke="#facc15" stroke-opacity="0.4" stroke-width="1.5" class="radar-ping" style="animation-delay: 0.8s;"/>
                <circle cx="160" cy="170" r="110" stroke="#f97316" stroke-opacity="0.25" stroke-width="1" class="radar-ping" style="animation-delay: 1.6s;"/>

                <!-- Satellite Antenna Dish Visual -->
                <g class="float-anim">
                    <!-- Base Stand -->
                    <path d="M140 220 L160 170 L180 220" stroke="#64748b" stroke-width="4" stroke-linecap="round"/>
                    <line x1="125" y1="220" x2="195" y2="220" stroke="#475569" stroke-width="4" stroke-linecap="round"/>

                    <!-- Curved Dish -->
                    <path d="M115 130 C125 185 195 185 205 130" stroke="#f59e0b" stroke-width="6" stroke-linecap="round" fill="#1e293b"/>
                    
                    <!-- Receiver Feed Horn -->
                    <line x1="160" y1="160" x2="160" y2="105" stroke="#facc15" stroke-width="3" stroke-linecap="round"/>
                    <circle cx="160" cy="105" r="7" fill="#ef4444" stroke="#fef08a" stroke-width="2" class="animate-ping"/>
                    
                    <!-- Latency Dropped Wave Icon -->
                    <path d="M140 75 Q160 60 180 75" stroke="#ef4444" stroke-width="3" stroke-linecap="round" stroke-dasharray="4 4"/>
                    <path d="M130 60 Q160 40 190 60" stroke="#ef4444" stroke-width="3" stroke-linecap="round" stroke-dasharray="4 4"/>
                </g>
            </svg>';

        case 'media_type': // 415 Unsupported Media Type
            return '
            <svg class="w-full h-full" viewBox="0 0 320 280" fill="none" xmlns="http://www.w3.org/2000/svg">
                <defs>
                    <radialGradient id="mediaGlow" cx="50%" cy="50%" r="50%">
                        <stop offset="0%" stop-color="#8b5cf6" stop-opacity="0.35"/>
                        <stop offset="100%" stop-color="#3b82f6" stop-opacity="0"/>
                    </radialGradient>
                </defs>
                <circle cx="160" cy="140" r="115" fill="url(#mediaGlow)"/>

                <g class="float-anim">
                    <!-- Central Security Filter Frame -->
                    <rect x="90" y="55" width="140" height="170" rx="16" fill="#0f172a" stroke="#8b5cf6" stroke-width="2.5" filter="drop-shadow(0 0 20px rgba(139,92,246,0.3))"/>
                    
                    <!-- Valid File Format Pill (Passing Through) -->
                    <rect x="110" y="75" width="100" height="32" rx="8" fill="#10b981" fill-opacity="0.15" stroke="#10b981" stroke-width="1.5"/>
                    <text x="145" y="96" font-family="Plus Jakarta Sans, sans-serif" font-weight="800" font-size="12" fill="#34d399">PDF / JPG</text>
                    <circle cx="125" cy="91" r="5" fill="#10b981"/>

                    <!-- Blocked File Format Pill (Blocked by Firewall) -->
                    <rect x="110" y="120" width="100" height="32" rx="8" fill="#ef4444" fill-opacity="0.2" stroke="#ef4444" stroke-width="2"/>
                    <text x="145" y="141" font-family="Plus Jakarta Sans, sans-serif" font-weight="800" font-size="12" fill="#f87171">.EXE / .SH</text>
                    <line x1="121" y1="132" x2="129" y2="140" stroke="#ef4444" stroke-width="2" stroke-linecap="round"/>
                    <line x1="129" y1="132" x2="121" y2="140" stroke="#ef4444" stroke-width="2" stroke-linecap="round"/>

                    <!-- Shield Barrier Mark -->
                    <circle cx="160" cy="185" r="18" fill="#8b5cf6" stroke="#c4b5fd" stroke-width="2"/>
                    <path d="M154 185 L158 189 L167 180" stroke="#ffffff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
                </g>
            </svg>';

        case 'bad_gateway': // 502 Bad Gateway
            return '
            <svg class="w-full h-full" viewBox="0 0 320 280" fill="none" xmlns="http://www.w3.org/2000/svg">
                <defs>
                    <radialGradient id="gwGlow" cx="50%" cy="50%" r="50%">
                        <stop offset="0%" stop-color="#6366f1" stop-opacity="0.35"/>
                        <stop offset="100%" stop-color="#ec4899" stop-opacity="0"/>
                    </radialGradient>
                </defs>
                <circle cx="160" cy="140" r="115" fill="url(#gwGlow)"/>

                <g class="float-anim">
                    <!-- Client Node (Left) -->
                    <rect x="50" y="110" width="60" height="60" rx="14" fill="#1e293b" stroke="#38bdf8" stroke-width="2"/>
                    <text x="80" y="145" font-family="Plus Jakarta Sans, sans-serif" font-weight="800" font-size="11" fill="#7dd3fc" text-anchor="middle">CLIENT</text>

                    <!-- Gateway Node (Center) -->
                    <rect x="130" y="100" width="60" height="80" rx="14" fill="#1e293b" stroke="#6366f1" stroke-width="2.5" filter="drop-shadow(0 0 15px rgba(99,102,241,0.4))"/>
                    <text x="160" y="145" font-family="Plus Jakarta Sans, sans-serif" font-weight="800" font-size="10" fill="#a5b4fc" text-anchor="middle">GATEWAY</text>

                    <!-- Backend Host Node (Right - Unreachable) -->
                    <rect x="210" y="110" width="60" height="60" rx="14" fill="#450a0a" stroke="#ef4444" stroke-width="2"/>
                    <text x="240" y="145" font-family="Plus Jakarta Sans, sans-serif" font-weight="800" font-size="10" fill="#fca5a5" text-anchor="middle">UPSTREAM</text>

                    <!-- Connection Link 1 (OK) -->
                    <line x1="110" y1="140" x2="130" y2="140" stroke="#38bdf8" stroke-width="3" stroke-linecap="round"/>

                    <!-- Connection Link 2 (Severed with Cross) -->
                    <line x1="190" y1="140" x2="200" y2="140" stroke="#ef4444" stroke-width="3" stroke-linecap="round"/>
                    <line x1="202" y1="135" x2="208" y2="145" stroke="#ef4444" stroke-width="3" stroke-linecap="round"/>
                    <line x1="208" y1="135" x2="202" y2="145" stroke="#ef4444" stroke-width="3" stroke-linecap="round"/>
                </g>
            </svg>';

        default: // Generic Fallback (400, 405, 409, 410, 413, 414, 422, etc.)
            return '
            <svg class="w-full h-full" viewBox="0 0 320 280" fill="none" xmlns="http://www.w3.org/2000/svg">
                <defs>
                    <radialGradient id="genGlow" cx="50%" cy="50%" r="50%">
                        <stop offset="0%" stop-color="#3b82f6" stop-opacity="0.35"/>
                        <stop offset="100%" stop-color="#6366f1" stop-opacity="0"/>
                    </radialGradient>
                </defs>
                <circle cx="160" cy="140" r="115" fill="url(#genGlow)"/>

                <g class="float-anim">
                    <!-- Concentric Hexagon Shield -->
                    <polygon points="160,50 240,95 240,185 160,230 80,185 80,95" 
                             fill="#0f172a" stroke="#60a5fa" stroke-width="3" filter="drop-shadow(0 0 20px rgba(59,130,246,0.35))"/>
                    
                    <polygon points="160,70 220,105 220,175 160,210 100,175 100,105" 
                             fill="none" stroke="#93c5fd" stroke-opacity="0.4" stroke-width="1.5" stroke-dasharray="6 4"/>

                    <!-- Big HTTP Code Watermark in center -->
                    <text x="160" y="152" font-family="Plus Jakarta Sans, sans-serif" font-weight="900" font-size="44" fill="#ffffff" text-anchor="middle" letter-spacing="-1">
                        ' . (int)$code . '
                    </text>

                    <!-- Alert Badge -->
                    <circle cx="160" cy="185" r="14" fill="#f59e0b" stroke="#ffffff" stroke-width="2"/>
                    <text x="160" y="191" font-family="sans-serif" font-weight="900" font-size="16" fill="#0f172a" text-anchor="middle">!</text>
                </g>
            </svg>';
    }
}

/**
 * Fungsi Utama Render Halaman Error
 */
function renderErrorPage(int $statusCode, ?string $customTitle = null, ?string $customDesc = null): void {
    if ($statusCode < 400 || $statusCode > 599) {
        $statusCode = 500;
    }

    http_response_code($statusCode);

    if (!headers_sent()) {
        header("X-Content-Type-Options: nosniff");
        header("X-Frame-Options: SAMEORIGIN");
        header("X-XSS-Protection: 1; mode=block");
        header("Referrer-Policy: strict-origin-when-cross-origin");
        header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
        header("Pragma: no-cache");
        header("Content-Security-Policy: default-src 'self' https: data: 'unsafe-inline' 'unsafe-eval'; img-src 'self' data: https:; font-src 'self' https://fonts.gstatic.com https://cdn.jsdelivr.net data:; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdn.jsdelivr.net; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; frame-ancestors 'none';");
    }

    $data = getErrorDetails($statusCode);

    // Sanitasi judul & deskripsi kustom (Proteksi XSS & Content Spoofing)
    if ($customTitle !== null) {
        $cleanTitle = strip_tags($customTitle);
        $cleanTitle = preg_replace('/[\r\n\t]+/', ' ', $cleanTitle);
        $cleanTitle = function_exists('mb_substr') ? mb_substr(trim($cleanTitle), 0, 100, 'UTF-8') : substr(trim($cleanTitle), 0, 100);
        $title = $cleanTitle !== '' ? $cleanTitle : $data['title'];
    } else {
        $title = $data['title'];
    }

    if ($customDesc !== null) {
        $cleanDesc = strip_tags($customDesc);
        $cleanDesc = preg_replace('/[\r\n\t]+/', ' ', $cleanDesc);
        $cleanDesc = function_exists('mb_substr') ? mb_substr(trim($cleanDesc), 0, 400, 'UTF-8') : substr(trim($cleanDesc), 0, 400);
        $desc = $cleanDesc !== '' ? $cleanDesc : $data['description'];
    } else {
        $desc = $data['description'];
    }

    $code           = $data['code'];
    $theme          = $data['theme'] ?? 'generic';
    $statusText     = $data['status'];
    $category       = $data['category'] ?? 'Status Galat';
    $badgeText      = $data['badge'];
    $icon           = $data['icon'];
    $suggestions    = $data['suggestions'];
    $primaryBtn     = $data['primary_btn'];
    $glowColor      = $data['glow_color'];
    $secondaryGlow  = $data['secondary_glow'] ?? 'rgba(59, 130, 246, 0.15)';
    $borderColor    = $data['border_color'];
    $badgeBg        = $data['badge_bg'];
    $btnBg          = $data['btn_bg'];

    // Resolusi path dasar absolut dinamis (anti broken-link)
    $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $basePath = '/';

    if (!empty($_SERVER['DOCUMENT_ROOT'])) {
        $docRoot = rtrim(str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT']) ?: $_SERVER['DOCUMENT_ROOT']), '/');
        $projectDir = rtrim(str_replace('\\', '/', dirname(__DIR__)), '/');
        if (strpos($projectDir, $docRoot) === 0) {
            $subPath = trim(substr($projectDir, strlen($docRoot)), '/');
            $basePath = $subPath === '' ? '/' : '/' . $subPath . '/';
        }
    }

    if ($basePath === '/' && preg_match('#^(/[^/]+)?/(error|api|apps|auth|config|dashboard|dokumentasi|includes|ppdb|uploads)/#i', $scriptName, $matches)) {
        if (!empty($matches[1])) {
            $basePath = $matches[1] . '/';
        }
    }

    $homeUrl      = $basePath . 'index.php';
    $newsUrl      = $basePath . 'berita.php';
    $loginUrl     = $basePath . 'auth/login.php';
    $contactUrl   = $basePath . 'kontak.php';
    $ppdbUrl      = $basePath . 'ppdb/ppdb.php';
    $dashboardUrl = $basePath . 'dashboard/index.php';

    // Resolusi URL Primary Button jika bukan action JS
    $primaryHref = null;
    if (isset($primaryBtn['href'])) {
        $rawHref = preg_replace('#^(\.\./)+#', '', $primaryBtn['href']);
        $primaryHref = $basePath . ltrim($rawHref, '/');
    }

    // Tiket Insiden Kriptografis Unik (Zero Session-ID Leakage)
    $incidentHash = hash('sha256', $statusCode . microtime(true) . ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'));
    $incidentId = 'ERR-' . strtoupper(substr($incidentHash, 0, 8));
    $timestamp = date('d/m/Y H:i:s T');
?>
<!DOCTYPE html>
<html lang="id" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <title><?= (int) $code ?> - <?= htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> | Manajemen-PHP</title>
    
    <!-- Google Fonts: Plus Jakarta Sans & JetBrains Mono -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;700&family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    <!-- Font Awesome 6 CDN -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.1/css/all.min.css">
    
    <!-- Tailwind CSS v4 CDN -->
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>

    <style>
        body {
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif;
        }
        .font-mono-code {
            font-family: 'JetBrains Mono', monospace;
        }
        /* Grid background pattern */
        .cyber-grid {
            background-image: radial-gradient(rgba(255, 255, 255, 0.07) 1px, transparent 1px);
            background-size: 28px 28px;
        }
        @keyframes floatSlow {
            0%, 100% { transform: translateY(0px) rotate(0deg); }
            50% { transform: translateY(-10px) rotate(1.5deg); }
        }
        @keyframes pulseGlow {
            0%, 100% { opacity: 0.45; transform: scale(1); }
            50% { opacity: 0.8; transform: scale(1.08); }
        }
        @keyframes scanLaser {
            0% { transform: translateY(-30px); opacity: 0.2; }
            50% { opacity: 1; }
            100% { transform: translateY(50px); opacity: 0.2; }
        }
        @keyframes spinSlow {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        @keyframes spinReverse {
            from { transform: rotate(360deg); }
            to { transform: rotate(0deg); }
        }
        @keyframes radarSweep {
            0% { transform: scale(0.7); opacity: 1; }
            100% { transform: scale(1.6); opacity: 0; }
        }
        .float-anim {
            animation: floatSlow 4.5s ease-in-out infinite;
        }
        .glow-pulse {
            animation: pulseGlow 5.5s ease-in-out infinite;
        }
        .scan-laser-anim {
            animation: scanLaser 2.8s ease-in-out infinite alternate;
        }
        .spin-gear {
            animation: spinSlow 14s linear infinite;
        }
        .spin-gear-reverse {
            animation: spinReverse 10s linear infinite;
        }
        .radar-ping {
            transform-origin: 160px 170px;
            animation: radarSweep 3s ease-out infinite;
        }
    </style>
</head>
<body class="min-h-screen bg-slate-950 text-slate-100 flex flex-col justify-between selection:bg-indigo-600 selection:text-white relative overflow-x-hidden cyber-grid">

    <!-- Ambient Glowing Orbs -->
    <div class="pointer-events-none fixed inset-0 overflow-hidden z-0">
        <div class="absolute -top-40 left-1/2 -translate-x-1/2 h-[550px] w-[650px] rounded-full blur-[140px] glow-pulse"
             style="background: radial-gradient(circle, <?= htmlspecialchars($glowColor, ENT_QUOTES, 'UTF-8') ?> 0%, transparent 70%);"></div>
        <div class="absolute -bottom-40 right-10 h-[450px] w-[450px] rounded-full blur-[130px] opacity-40"
             style="background: radial-gradient(circle, <?= htmlspecialchars($secondaryGlow, ENT_QUOTES, 'UTF-8') ?> 0%, transparent 70%);"></div>
    </div>

    <!-- Header Navigation -->
    <header class="relative z-20 border-b border-white/5 bg-slate-950/70 backdrop-blur-xl">
        <div class="mx-auto flex max-w-7xl items-center justify-between px-6 py-4">
            <a href="<?= htmlspecialchars($homeUrl, ENT_QUOTES, 'UTF-8') ?>" class="flex items-center gap-3 text-lg font-bold tracking-tight text-white group">
                <span class="flex h-10 w-10 items-center justify-center rounded-2xl bg-gradient-to-tr from-blue-600 to-indigo-500 text-white shadow-lg shadow-blue-500/25 transition group-hover:scale-105">
                    <i class="fa-solid fa-graduation-cap text-base"></i>
                </span>
                <div class="flex flex-col">
                    <span class="leading-tight font-extrabold text-white">Manajemen<span class="text-blue-400">-PHP</span></span>
                    <span class="text-[10px] text-slate-400 font-medium">Sistem Manajemen Sekolah</span>
                </div>
            </a>

            <!-- Quick Header Links -->
            <div class="flex items-center gap-2 sm:gap-3 text-xs font-semibold">
                <a href="<?= htmlspecialchars($homeUrl, ENT_QUOTES, 'UTF-8') ?>" class="hidden sm:inline-flex rounded-xl px-3.5 py-2 text-slate-300 hover:text-white hover:bg-white/5 transition">
                    <i class="fa-solid fa-house mr-1.5 opacity-70"></i> Beranda
                </a>
                <a href="<?= htmlspecialchars($ppdbUrl, ENT_QUOTES, 'UTF-8') ?>" class="hidden md:inline-flex rounded-xl px-3.5 py-2 text-slate-300 hover:text-white hover:bg-white/5 transition">
                    <i class="fa-solid fa-user-graduate mr-1.5 opacity-70"></i> PPDB
                </a>
                <a href="<?= htmlspecialchars($newsUrl, ENT_QUOTES, 'UTF-8') ?>" class="rounded-xl border border-white/10 bg-white/5 px-3.5 py-2 text-slate-200 hover:bg-white/10 transition">
                    Berita Sekolah →
                </a>
            </div>
        </div>
    </header>

    <!-- Main Dynamic Error Canvas -->
    <main class="relative z-10 mx-auto w-full max-w-7xl px-4 sm:px-6 py-8 sm:py-12 flex-1 flex flex-col justify-center">

        <?php if ($theme === 'cosmic'): ?>
            <!-- ======================================================== -->
            <!-- ARKETIPE 1: 404 (Cosmic Explorer - 2-Column Split Hero) -->
            <!-- ======================================================== -->
            <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 items-center max-w-6xl mx-auto w-full">
                <!-- Visual Column (Left) -->
                <div class="lg:col-span-5 flex flex-col items-center justify-center">
                    <div class="w-full max-w-[340px] sm:max-w-[400px] aspect-square rounded-3xl border border-purple-500/20 bg-slate-900/60 backdrop-blur-2xl p-6 shadow-2xl shadow-purple-950/40 relative overflow-hidden flex items-center justify-center">
                        <div class="absolute inset-0 bg-gradient-to-tr from-purple-900/20 via-transparent to-indigo-900/20 pointer-events-none"></div>
                        <?= renderErrorSvg($theme, $data['accent'] ?? 'purple', $code) ?>
                    </div>
                </div>

                <!-- Content & Quick Navigation Column (Right) -->
                <div class="lg:col-span-7 flex flex-col items-center lg:items-start text-center lg:text-left space-y-5">
                    <div class="inline-flex items-center gap-2 rounded-full border px-4 py-1.5 text-xs font-bold uppercase tracking-wider <?= htmlspecialchars($badgeBg, ENT_QUOTES, 'UTF-8') ?> shadow-sm">
                        <span class="h-2 w-2 rounded-full bg-current animate-ping"></span>
                        <?= htmlspecialchars($badgeText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    </div>

                    <div class="space-y-2">
                        <h1 class="text-6xl sm:text-7xl font-black tracking-tight text-transparent bg-clip-text bg-gradient-to-r from-purple-200 via-indigo-200 to-slate-400 select-none">
                            404 Not Found
                        </h1>
                        <h2 class="text-2xl sm:text-3xl font-bold text-white tracking-tight">
                            <?= htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                        </h2>
                        <p class="text-sm sm:text-base text-slate-300 max-w-xl leading-relaxed">
                            <?= htmlspecialchars($desc, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                        </p>
                    </div>

                    <!-- Interactive Quick Search / Jump Box -->
                    <div class="w-full max-w-xl rounded-2xl border border-white/10 bg-slate-900/80 p-3 shadow-lg backdrop-blur-md">
                        <form action="<?= htmlspecialchars($newsUrl, ENT_QUOTES, 'UTF-8') ?>" method="GET" class="flex items-center gap-2">
                            <i class="fa-solid fa-magnifying-glass text-slate-400 pl-3"></i>
                            <input type="text"
                                   id="quickSearchInput"
                                   name="cari"
                                   placeholder="Cari modul atau pengumuman sekolah..."
                                   class="w-full bg-transparent px-2 py-1.5 text-xs sm:text-sm text-white placeholder-slate-400 focus:outline-none">
                            <button type="submit" class="rounded-xl bg-purple-600 hover:bg-purple-500 px-4 py-2 text-xs font-bold text-white transition cursor-pointer">
                                Cari
                            </button>
                        </form>
                    </div>

                    <!-- Popular Destination Pills -->
                    <?php if (!empty($data['popular_links'])): ?>
                        <div class="w-full max-w-xl text-left">
                            <p class="text-xs font-semibold text-slate-400 mb-2.5">
                                <i class="fa-solid fa-compass text-purple-400 mr-1"></i> Modul Populer Sekolah:
                            </p>
                            <div class="flex flex-wrap gap-2">
                                <?php foreach ($data['popular_links'] as $link): ?>
                                    <a href="<?= htmlspecialchars($basePath . ltrim($link['href'], '/'), ENT_QUOTES, 'UTF-8') ?>"
                                       class="inline-flex items-center gap-2 rounded-xl border border-white/10 bg-white/5 hover:bg-purple-600/20 hover:border-purple-500/40 px-3 py-1.5 text-xs text-slate-200 transition">
                                        <i class="<?= htmlspecialchars($link['icon'], ENT_QUOTES, 'UTF-8') ?> text-purple-400"></i>
                                        <?= htmlspecialchars($link['label'], ENT_QUOTES, 'UTF-8') ?>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Action Buttons -->
                    <div class="flex flex-wrap items-center gap-3 pt-2">
                        <a href="<?= htmlspecialchars($primaryHref ?? $homeUrl, ENT_QUOTES, 'UTF-8') ?>"
                           class="inline-flex items-center gap-2 rounded-xl <?= htmlspecialchars($btnBg, ENT_QUOTES, 'UTF-8') ?> px-6 py-3 text-xs sm:text-sm font-bold transition shadow-lg">
                            <?= $primaryBtn['icon'] ?? '<i class="fa-solid fa-house"></i>' ?>
                            <?= htmlspecialchars($primaryBtn['label'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                        </a>
                        <button type="button"
                                data-action="back"
                                class="inline-flex items-center gap-2 rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-5 py-3 text-xs sm:text-sm font-bold text-slate-200 transition cursor-pointer">
                            <i class="fa-solid fa-arrow-left text-xs"></i> Kembali
                        </button>
                    </div>
                </div>
            </div>

        <?php elseif ($theme === 'security'): ?>
            <!-- ======================================================== -->
            <!-- ARKETIPE 2: 403 (Security Clearance Checkpoint)          -->
            <!-- ======================================================== -->
            <div class="max-w-4xl mx-auto w-full">
                <div class="w-full rounded-3xl border <?= htmlspecialchars($borderColor, ENT_QUOTES, 'UTF-8') ?> bg-slate-900/75 p-6 sm:p-12 backdrop-blur-2xl shadow-2xl shadow-rose-950/40 relative overflow-hidden">
                    
                    <!-- Top Security Banner Tape -->
                    <div class="flex items-center justify-between border-b border-rose-500/20 pb-4 mb-8">
                        <div class="flex items-center gap-3">
                            <span class="flex h-3 w-3 rounded-full bg-rose-500 animate-ping"></span>
                            <span class="font-mono-code text-xs font-bold text-rose-400 uppercase tracking-widest">
                                SECURITY LEVEL: RESTRICTED // CLEARANCE REQUIRED
                            </span>
                        </div>
                        <span class="hidden sm:inline-block font-mono-code text-xs text-slate-500">
                            ID: <?= htmlspecialchars($incidentId, ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-12 gap-8 items-center">
                        <!-- Holographic Scanner Visual -->
                        <div class="md:col-span-5 flex justify-center">
                            <div class="w-full max-w-[280px] aspect-square rounded-2xl border border-rose-500/30 bg-rose-950/20 p-4 flex items-center justify-center relative">
                                <?= renderErrorSvg($theme, 'rose', $code) ?>
                            </div>
                        </div>

                        <!-- Security Notice & Permissions Matrix -->
                        <div class="md:col-span-7 space-y-4 text-center md:text-left">
                            <div class="inline-flex items-center gap-2 rounded-full border px-3.5 py-1 text-xs font-bold uppercase tracking-wider <?= htmlspecialchars($badgeBg, ENT_QUOTES, 'UTF-8') ?>">
                                <?= htmlspecialchars($badgeText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                            </div>

                            <h1 class="text-3xl sm:text-4xl font-extrabold text-white tracking-tight">
                                <?= htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                            </h1>

                            <p class="text-sm text-slate-300 leading-relaxed">
                                <?= htmlspecialchars($desc, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                            </p>

                            <!-- Role Matrix Box -->
                            <div class="rounded-xl border border-white/5 bg-slate-950/60 p-4 text-left space-y-2">
                                <p class="text-[11px] font-bold text-slate-400 uppercase tracking-wider flex items-center gap-2">
                                    <i class="fa-solid fa-lock text-rose-400"></i> Informasi Hak Akses Modul:
                                </p>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 text-xs">
                                    <div class="p-2.5 rounded-lg bg-rose-500/10 border border-rose-500/20 text-rose-300">
                                        <span class="block text-[10px] text-slate-400">Status Anda</span>
                                        <strong>Hak Akses Tidak Cukup</strong>
                                    </div>
                                    <div class="p-2.5 rounded-lg bg-blue-500/10 border border-blue-500/20 text-blue-300">
                                        <span class="block text-[10px] text-slate-400">Otoritas Diperlukan</span>
                                        <strong>Admin / Pengelola Modul</strong>
                                    </div>
                                </div>
                            </div>

                            <!-- Actions -->
                            <div class="flex flex-wrap items-center justify-center md:justify-start gap-3 pt-2">
                                <a href="<?= htmlspecialchars($dashboardUrl, ENT_QUOTES, 'UTF-8') ?>"
                                   class="inline-flex items-center gap-2 rounded-xl <?= htmlspecialchars($btnBg, ENT_QUOTES, 'UTF-8') ?> px-5 py-3 text-xs sm:text-sm font-bold transition shadow-lg">
                                    <i class="fa-solid fa-gauge-high"></i> Ke Dasbor Utama
                                </a>
                                <a href="<?= htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8') ?>"
                                   class="inline-flex items-center gap-2 rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-5 py-3 text-xs sm:text-sm font-bold text-slate-200 transition">
                                    <i class="fa-solid fa-user-lock"></i> Ganti Akun / Login
                                </a>
                                <button type="button" data-action="back"
                                        class="inline-flex items-center gap-2 rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-4 py-3 text-xs sm:text-sm font-bold text-slate-400 hover:text-white transition cursor-pointer">
                                    <i class="fa-solid fa-arrow-left"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                </div>
            </div>

        <?php elseif ($theme === 'server_crash'): ?>
            <!-- ======================================================== -->
            <!-- ARKETIPE 3: 500 (Server Crash & System Diagnostic Hub)  -->
            <!-- ======================================================== -->
            <div class="max-w-4xl mx-auto w-full">
                <div class="w-full rounded-3xl border <?= htmlspecialchars($borderColor, ENT_QUOTES, 'UTF-8') ?> bg-slate-900/80 p-6 sm:p-12 backdrop-blur-2xl shadow-2xl shadow-red-950/40 relative overflow-hidden">
                    
                    <div class="flex flex-col items-center text-center space-y-4 mb-6">
                        <!-- Server Crash SVG -->
                        <div class="w-full max-w-[260px] aspect-square flex items-center justify-center mb-2">
                            <?= renderErrorSvg($theme, 'amber', $code) ?>
                        </div>

                        <div class="inline-flex items-center gap-2 rounded-full border px-4 py-1.5 text-xs font-bold uppercase tracking-wider <?= htmlspecialchars($badgeBg, ENT_QUOTES, 'UTF-8') ?>">
                            <span class="h-2 w-2 rounded-full bg-current animate-ping"></span>
                            <?= htmlspecialchars($badgeText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                        </div>

                        <h1 class="text-3xl sm:text-5xl font-black text-white tracking-tight">
                            <?= htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                        </h1>

                        <p class="text-sm sm:text-base text-slate-300 max-w-xl leading-relaxed">
                            <?= htmlspecialchars($desc, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                        </p>
                    </div>

                    <!-- Interactive Diagnostic Terminal Box -->
                    <div class="rounded-2xl border border-white/10 bg-slate-950/90 p-5 font-mono-code text-xs text-left max-w-2xl mx-auto mb-8 shadow-inner">
                        <div class="flex items-center justify-between border-b border-white/10 pb-3 mb-3">
                            <div class="flex items-center gap-2 text-slate-400">
                                <span class="h-3 w-3 rounded-full bg-red-500/80 inline-block"></span>
                                <span class="h-3 w-3 rounded-full bg-yellow-500/80 inline-block"></span>
                                <span class="h-3 w-3 rounded-full bg-green-500/80 inline-block"></span>
                                <span class="text-[11px] text-slate-400 pl-2">System Diagnostics Telemetry</span>
                            </div>
                            <button type="button"
                                    id="copyIncidentBtn"
                                    data-incident="<?= htmlspecialchars($incidentId, ENT_QUOTES, 'UTF-8') ?>"
                                    class="inline-flex items-center gap-1.5 rounded-lg bg-white/10 hover:bg-white/20 px-2.5 py-1 text-[11px] text-slate-200 transition cursor-pointer">
                                <i class="fa-regular fa-copy"></i>
                                <span>Salin Tiket</span>
                            </button>
                        </div>
                        <div class="space-y-1.5 text-slate-300 text-[12px]">
                            <p><span class="text-slate-500">TICKET_ID  :</span> <strong class="text-amber-400"><?= htmlspecialchars($incidentId, ENT_QUOTES, 'UTF-8') ?></strong></p>
                            <p><span class="text-slate-500">HTTP_CODE  :</span> <span class="text-red-400">500 Internal Anomaly</span></p>
                            <p><span class="text-slate-500">TIMESTAMP  :</span> <?= htmlspecialchars($timestamp, ENT_QUOTES, 'UTF-8') ?></p>
                            <p><span class="text-slate-500">PRIVACY    :</span> <span class="text-emerald-400">Trace Hidden (Defense-in-Depth Active)</span></p>
                        </div>
                    </div>

                    <!-- Actions -->
                    <div class="flex flex-wrap items-center justify-center gap-3">
                        <button type="button"
                                data-action="reload"
                                class="inline-flex items-center gap-2 rounded-xl <?= htmlspecialchars($btnBg, ENT_QUOTES, 'UTF-8') ?> px-6 py-3.5 text-xs sm:text-sm font-bold transition shadow-lg cursor-pointer">
                            <i class="fa-solid fa-rotate-right"></i>
                            Coba Muat Ulang Halaman
                        </button>
                        <a href="<?= htmlspecialchars($contactUrl, ENT_QUOTES, 'UTF-8') ?>"
                           class="inline-flex items-center gap-2 rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-5 py-3.5 text-xs sm:text-sm font-bold text-slate-200 transition">
                            <i class="fa-solid fa-headset text-xs"></i> Lapor Tim IT Sekolah
                        </a>
                        <a href="<?= htmlspecialchars($homeUrl, ENT_QUOTES, 'UTF-8') ?>"
                           class="inline-flex items-center gap-2 rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-5 py-3.5 text-xs sm:text-sm font-bold text-slate-400 hover:text-white transition">
                            <i class="fa-solid fa-house text-xs"></i> Beranda
                        </a>
                    </div>

                </div>
            </div>

        <?php elseif ($theme === 'maintenance'): ?>
            <!-- ======================================================== -->
            <!-- ARKETIPE 4: 503 (Maintenance Dashboard & Auto-Refresh)   -->
            <!-- ======================================================== -->
            <div class="max-w-4xl mx-auto w-full">
                <div class="w-full rounded-3xl border <?= htmlspecialchars($borderColor, ENT_QUOTES, 'UTF-8') ?> bg-slate-900/80 p-6 sm:p-12 backdrop-blur-2xl shadow-2xl shadow-emerald-950/40 relative overflow-hidden text-center">
                    
                    <div class="w-full max-w-[280px] aspect-square mx-auto mb-4 flex items-center justify-center">
                        <?= renderErrorSvg($theme, 'emerald', $code) ?>
                    </div>

                    <div class="inline-flex items-center gap-2 rounded-full border px-4 py-1.5 text-xs font-bold uppercase tracking-wider <?= htmlspecialchars($badgeBg, ENT_QUOTES, 'UTF-8') ?> mb-3">
                        <span class="h-2 w-2 rounded-full bg-emerald-400 animate-ping"></span>
                        <?= htmlspecialchars($badgeText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    </div>

                    <h1 class="text-3xl sm:text-5xl font-black text-white tracking-tight mb-3">
                        <?= htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    </h1>

                    <p class="text-sm sm:text-base text-slate-300 max-w-xl mx-auto leading-relaxed mb-6">
                        <?= htmlspecialchars($desc, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    </p>

                    <!-- Auto-Refresh Live Counter Card -->
                    <div class="rounded-2xl border border-emerald-500/20 bg-emerald-950/20 p-5 max-w-md mx-auto mb-8 backdrop-blur-md">
                        <p class="text-xs font-semibold text-emerald-300 mb-2">
                            <i class="fa-solid fa-arrows-rotate mr-1.5 animate-spin"></i> Memeriksa ketersediaan sistem dalam:
                        </p>
                        <div class="flex items-center justify-center gap-3">
                            <div class="text-3xl sm:text-4xl font-mono-code font-black text-emerald-400" id="countdownTimer">
                                30
                            </div>
                            <span class="text-xs text-slate-400 font-medium">detik</span>
                        </div>
                        <p class="text-[11px] text-slate-400 mt-2">Halaman akan memuat ulang secara otomatis ketika hitungan selesai.</p>
                    </div>

                    <!-- Actions -->
                    <div class="flex flex-wrap items-center justify-center gap-3">
                        <button type="button"
                                data-action="reload"
                                class="inline-flex items-center gap-2 rounded-xl <?= htmlspecialchars($btnBg, ENT_QUOTES, 'UTF-8') ?> px-6 py-3.5 text-xs sm:text-sm font-bold transition shadow-lg cursor-pointer">
                            <i class="fa-solid fa-arrows-rotate"></i> Periksa Sekarang
                        </button>
                        <button type="button"
                                id="toggleAutoRefreshBtn"
                                class="inline-flex items-center gap-2 rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-5 py-3.5 text-xs sm:text-sm font-bold text-slate-200 transition cursor-pointer">
                            <i class="fa-solid fa-pause"></i> Jeda Hitung Mundur
                        </button>
                    </div>

                </div>
            </div>

        <?php elseif ($theme === 'auth_gate'): ?>
            <!-- ======================================================== -->
            <!-- ARKETIPE 5: 401 (Identity Gateway & Portal Login Pass)   -->
            <!-- ======================================================== -->
            <div class="max-w-4xl mx-auto w-full">
                <div class="w-full rounded-3xl border <?= htmlspecialchars($borderColor, ENT_QUOTES, 'UTF-8') ?> bg-slate-900/80 p-6 sm:p-12 backdrop-blur-2xl shadow-2xl shadow-sky-950/40 relative overflow-hidden">
                    
                    <div class="grid grid-cols-1 md:grid-cols-12 gap-8 items-center">
                        <div class="md:col-span-5 flex justify-center">
                            <div class="w-full max-w-[280px] aspect-square flex items-center justify-center">
                                <?= renderErrorSvg($theme, 'sky', $code) ?>
                            </div>
                        </div>

                        <div class="md:col-span-7 space-y-4 text-center md:text-left">
                            <div class="inline-flex items-center gap-2 rounded-full border px-3.5 py-1 text-xs font-bold uppercase tracking-wider <?= htmlspecialchars($badgeBg, ENT_QUOTES, 'UTF-8') ?>">
                                <?= htmlspecialchars($badgeText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                            </div>

                            <h1 class="text-3xl sm:text-4xl font-extrabold text-white tracking-tight">
                                <?= htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                            </h1>

                            <p class="text-sm text-slate-300 leading-relaxed">
                                <?= htmlspecialchars($desc, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                            </p>

                            <!-- Role Quick Selector -->
                            <?php if (!empty($data['roles_pills'])): ?>
                                <div class="rounded-xl border border-white/5 bg-slate-950/60 p-4 text-left">
                                    <p class="text-[11px] font-bold text-slate-400 uppercase tracking-wider mb-2.5">
                                        <i class="fa-solid fa-users text-sky-400 mr-1"></i> Area Masuk Civitas Sekolah:
                                    </p>
                                    <div class="flex flex-wrap gap-2">
                                        <?php foreach ($data['roles_pills'] as $role): ?>
                                            <span class="rounded-lg bg-sky-500/10 border border-sky-500/20 px-2.5 py-1 text-xs text-sky-300">
                                                <?= htmlspecialchars($role, ENT_QUOTES, 'UTF-8') ?>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <!-- Actions -->
                            <div class="flex flex-wrap items-center justify-center md:justify-start gap-3 pt-2">
                                <a href="<?= htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8') ?>"
                                   class="inline-flex items-center gap-2 rounded-xl <?= htmlspecialchars($btnBg, ENT_QUOTES, 'UTF-8') ?> px-6 py-3.5 text-xs sm:text-sm font-bold transition shadow-lg">
                                    <i class="fa-solid fa-key"></i> Masuk ke Akun Sekarang
                                </a>
                                <a href="<?= htmlspecialchars($homeUrl, ENT_QUOTES, 'UTF-8') ?>"
                                   class="inline-flex items-center gap-2 rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-5 py-3.5 text-xs sm:text-sm font-bold text-slate-200 transition">
                                    <i class="fa-solid fa-house"></i> Beranda
                                </a>
                            </div>
                        </div>
                    </div>

                </div>
            </div>

        <?php elseif ($theme === 'cooldown'): ?>
            <!-- ======================================================== -->
            <!-- ARKETIPE 6: 429 (Tachometer Cooldown Chamber)            -->
            <!-- ======================================================== -->
            <div class="max-w-4xl mx-auto w-full">
                <div class="w-full rounded-3xl border <?= htmlspecialchars($borderColor, ENT_QUOTES, 'UTF-8') ?> bg-slate-900/80 p-6 sm:p-12 backdrop-blur-2xl shadow-2xl shadow-rose-950/40 relative overflow-hidden text-center">
                    
                    <div class="w-full max-w-[280px] aspect-square mx-auto mb-4 flex items-center justify-center">
                        <?= renderErrorSvg($theme, 'rose', $code) ?>
                    </div>

                    <div class="inline-flex items-center gap-2 rounded-full border px-4 py-1.5 text-xs font-bold uppercase tracking-wider <?= htmlspecialchars($badgeBg, ENT_QUOTES, 'UTF-8') ?> mb-3">
                        <span class="h-2 w-2 rounded-full bg-rose-500 animate-ping"></span>
                        <?= htmlspecialchars($badgeText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    </div>

                    <h1 class="text-3xl sm:text-4xl font-extrabold text-white tracking-tight mb-3">
                        <?= htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    </h1>

                    <p class="text-sm sm:text-base text-slate-300 max-w-xl mx-auto leading-relaxed mb-6">
                        <?= htmlspecialchars($desc, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    </p>

                    <!-- Interactive Cooldown Counter Box -->
                    <div class="rounded-2xl border border-rose-500/30 bg-rose-950/30 p-5 max-w-md mx-auto mb-8 backdrop-blur-md">
                        <p class="text-xs font-bold text-rose-300 uppercase tracking-wider mb-2">
                            <i class="fa-solid fa-fire text-amber-400 mr-1.5"></i> Periode Pendinginan Firewall:
                        </p>
                        <div class="flex items-center justify-center gap-3">
                            <div class="text-4xl sm:text-5xl font-mono-code font-black text-rose-400" id="cooldownTimer">
                                45
                            </div>
                            <span class="text-xs text-slate-400 font-medium">detik tersisa</span>
                        </div>
                        <div class="w-full bg-slate-800 rounded-full h-1.5 mt-4 overflow-hidden">
                            <div id="cooldownBar" class="bg-gradient-to-r from-rose-500 to-amber-500 h-1.5 rounded-full transition-all duration-1000" style="width: 100%;"></div>
                        </div>
                    </div>

                    <!-- Actions -->
                    <div class="flex flex-wrap items-center justify-center gap-3">
                        <button type="button"
                                id="cooldownReloadBtn"
                                data-action="reload"
                                disabled
                                class="inline-flex items-center gap-2 rounded-xl bg-slate-800 text-slate-500 px-6 py-3.5 text-xs sm:text-sm font-bold transition shadow-lg cursor-not-allowed">
                            <i class="fa-solid fa-rotate-right"></i> Tunggu Hingga Selesai...
                        </button>
                        <a href="<?= htmlspecialchars($homeUrl, ENT_QUOTES, 'UTF-8') ?>"
                           class="inline-flex items-center gap-2 rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-5 py-3.5 text-xs sm:text-sm font-bold text-slate-200 transition">
                            <i class="fa-solid fa-house"></i> Ke Beranda
                        </a>
                    </div>

                </div>
            </div>

        <?php elseif ($theme === 'timeout'): ?>
            <!-- ======================================================== -->
            <!-- ARKETIPE 7: 408 / 504 (Network Radar & Connection Test)  -->
            <!-- ======================================================== -->
            <div class="max-w-4xl mx-auto w-full">
                <div class="w-full rounded-3xl border <?= htmlspecialchars($borderColor, ENT_QUOTES, 'UTF-8') ?> bg-slate-900/80 p-6 sm:p-12 backdrop-blur-2xl shadow-2xl shadow-amber-950/40 relative overflow-hidden text-center">
                    
                    <div class="w-full max-w-[280px] aspect-square mx-auto mb-4 flex items-center justify-center">
                        <?= renderErrorSvg($theme, $data['accent'] ?? 'yellow', $code) ?>
                    </div>

                    <div class="inline-flex items-center gap-2 rounded-full border px-4 py-1.5 text-xs font-bold uppercase tracking-wider <?= htmlspecialchars($badgeBg, ENT_QUOTES, 'UTF-8') ?> mb-3">
                        <span class="h-2 w-2 rounded-full bg-yellow-400 animate-ping"></span>
                        <?= htmlspecialchars($badgeText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    </div>

                    <h1 class="text-3xl sm:text-5xl font-black text-white tracking-tight mb-3">
                        <?= htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    </h1>

                    <p class="text-sm sm:text-base text-slate-300 max-w-xl mx-auto leading-relaxed mb-6">
                        <?= htmlspecialchars($desc, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    </p>

                    <!-- Interactive Connection Tester Tool -->
                    <div class="rounded-2xl border border-white/10 bg-slate-950/60 p-5 max-w-md mx-auto mb-8 text-left backdrop-blur-md">
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-xs font-bold text-slate-300 flex items-center gap-2">
                                <i class="fa-solid fa-network-wired text-yellow-400"></i> Alat Uji Koneksi Lokal:
                            </span>
                            <span id="pingStatusBadge" class="text-[11px] px-2.5 py-0.5 rounded-full bg-slate-800 text-slate-400 font-semibold">
                                Siap Pengujian
                            </span>
                        </div>
                        <p id="pingStatusText" class="text-xs text-slate-400 mb-3">
                            Klik tombol di bawah untuk mendeteksi apakah peramban Anda terhubung normal ke internet atau sedang offline.
                        </p>
                        <button type="button"
                                id="testPingBtn"
                                class="w-full inline-flex items-center justify-center gap-2 rounded-xl bg-yellow-500 hover:bg-yellow-400 text-slate-950 font-bold py-2 text-xs transition cursor-pointer">
                            <i class="fa-solid fa-signal"></i> Uji Status Koneksi Sekarang
                        </button>
                    </div>

                    <!-- Actions -->
                    <div class="flex flex-wrap items-center justify-center gap-3">
                        <button type="button"
                                data-action="reload"
                                class="inline-flex items-center gap-2 rounded-xl <?= htmlspecialchars($btnBg, ENT_QUOTES, 'UTF-8') ?> px-6 py-3.5 text-xs sm:text-sm font-bold transition shadow-lg cursor-pointer">
                            <i class="fa-solid fa-rotate-right"></i> Coba Kirim Ulang
                        </button>
                        <a href="<?= htmlspecialchars($homeUrl, ENT_QUOTES, 'UTF-8') ?>"
                           class="inline-flex items-center gap-2 rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-5 py-3.5 text-xs sm:text-sm font-bold text-slate-200 transition">
                            <i class="fa-solid fa-house"></i> Kembali ke Beranda
                        </a>
                    </div>

                </div>
            </div>

        <?php else: ?>
            <!-- ======================================================== -->
            <!-- ARKETIPE UMUM (400, 402, 405, 409, 410, 413, 414, 415,  -->
            <!-- 419, 422, 502) — Desain Elegan dengan Karakter Unik     -->
            <!-- ======================================================== -->
            <div class="max-w-4xl mx-auto w-full">
                <div class="w-full rounded-3xl border <?= htmlspecialchars($borderColor, ENT_QUOTES, 'UTF-8') ?> bg-slate-900/80 p-6 sm:p-12 backdrop-blur-2xl shadow-2xl shadow-black/60 relative overflow-hidden text-center">
                    
                    <!-- Decorative Light Beam -->
                    <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-white/20 to-transparent"></div>

                    <!-- Bespoke SVG Illustration per Code -->
                    <div class="w-full max-w-[260px] aspect-square mx-auto mb-4 flex items-center justify-center">
                        <?= renderErrorSvg($theme, $data['accent'] ?? 'blue', $code) ?>
                    </div>

                    <!-- Status Badge -->
                    <div class="inline-flex items-center gap-2 rounded-full border px-4 py-1 text-xs font-bold uppercase tracking-wider <?= htmlspecialchars($badgeBg, ENT_QUOTES, 'UTF-8') ?> mb-3 shadow-sm">
                        <span class="h-2 w-2 rounded-full bg-current animate-ping"></span>
                        <?= htmlspecialchars($badgeText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    </div>

                    <!-- Main Code & Title -->
                    <h1 class="text-3xl sm:text-5xl font-black text-white tracking-tight mb-3">
                        <?= htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    </h1>

                    <p class="text-sm sm:text-base text-slate-300 max-w-2xl mx-auto leading-relaxed mb-6 font-normal">
                        <?= htmlspecialchars($desc, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    </p>

                    <!-- Troubleshooting Suggestions Card -->
                    <?php if (!empty($suggestions) && is_array($suggestions)): ?>
                        <div class="mb-8 rounded-2xl border border-white/5 bg-slate-950/60 p-5 text-left max-w-xl mx-auto backdrop-blur-sm">
                            <p class="text-xs font-bold uppercase tracking-wider text-slate-400 mb-3 flex items-center gap-2">
                                <i class="fa-solid fa-lightbulb text-amber-400"></i> Saran Tindakan Solutif:
                            </p>
                            <ul class="space-y-2 text-xs text-slate-300">
                                <?php foreach ($suggestions as $suggestion): ?>
                                    <li class="flex items-start gap-2">
                                        <span class="text-blue-400 mt-0.5">•</span>
                                        <span><?= htmlspecialchars((string) $suggestion, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <!-- Action Buttons -->
                    <div class="flex flex-wrap items-center justify-center gap-3">
                        <?php if (isset($primaryBtn['action'])): ?>
                            <button type="button"
                                    data-action="<?= htmlspecialchars($primaryBtn['action'], ENT_QUOTES, 'UTF-8') ?>"
                                    class="inline-flex items-center gap-2 rounded-xl <?= htmlspecialchars($btnBg, ENT_QUOTES, 'UTF-8') ?> px-6 py-3.5 text-xs sm:text-sm font-bold transition shadow-lg cursor-pointer">
                                <span><?= $primaryBtn['icon'] ?? '<i class="fa-solid fa-bolt"></i>' ?></span>
                                <?= htmlspecialchars($primaryBtn['label'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                            </button>
                        <?php else: ?>
                            <a href="<?= htmlspecialchars($primaryHref ?? $homeUrl, ENT_QUOTES, 'UTF-8') ?>"
                               class="inline-flex items-center gap-2 rounded-xl <?= htmlspecialchars($btnBg, ENT_QUOTES, 'UTF-8') ?> px-6 py-3.5 text-xs sm:text-sm font-bold transition shadow-lg">
                                <span><?= $primaryBtn['icon'] ?? '<i class="fa-solid fa-bolt"></i>' ?></span>
                                <?= htmlspecialchars($primaryBtn['label'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                            </a>
                        <?php endif; ?>

                        <button type="button"
                                data-action="back"
                                class="inline-flex items-center gap-2 rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-5 py-3.5 text-xs sm:text-sm font-bold text-slate-200 transition cursor-pointer">
                            <i class="fa-solid fa-arrow-left text-xs"></i> Kembali
                        </button>

                        <a href="<?= htmlspecialchars($homeUrl, ENT_QUOTES, 'UTF-8') ?>"
                           class="inline-flex items-center gap-2 rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-5 py-3.5 text-xs sm:text-sm font-bold text-slate-200 transition">
                            <i class="fa-solid fa-house text-xs"></i> Beranda
                        </a>
                    </div>

                </div>
            </div>
        <?php endif; ?>

    </main>

    <!-- Footer -->
    <footer class="relative z-20 border-t border-white/5 bg-slate-950/80 py-6 text-center text-xs text-slate-500 backdrop-blur-md">
        <div class="mx-auto max-w-7xl px-6 flex flex-col sm:flex-row items-center justify-between gap-4">
            <p>© <?= date('Y') ?> Manajemen-PHP — Sistem Terpadu Manajemen Sekolah Modern.</p>
            <div class="flex flex-wrap items-center justify-center gap-3">
                <a href="<?= htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8') ?>" class="hover:text-slate-300 transition">Login Akun</a>
                <span>•</span>
                <a href="<?= htmlspecialchars($dashboardUrl, ENT_QUOTES, 'UTF-8') ?>" class="hover:text-slate-300 transition">Dashboard</a>
                <span>•</span>
                <a href="<?= htmlspecialchars($contactUrl, ENT_QUOTES, 'UTF-8') ?>" class="hover:text-slate-300 transition">Kontak Tim IT</a>
                <span>•</span>
                <span class="font-mono-code text-slate-400" title="Referensi Pelaporan Insiden">Tiket: <?= htmlspecialchars($incidentId, ENT_QUOTES, 'UTF-8') ?></span>
                <span>•</span>
                <span class="text-slate-600"><?= htmlspecialchars($timestamp, ENT_QUOTES, 'UTF-8') ?></span>
            </div>
        </div>
    </footer>

    <!-- Toast Notification Container -->
    <div id="toastContainer" class="fixed bottom-6 right-6 z-50 flex flex-col gap-2 pointer-events-none"></div>

    <!-- Safe Event Listener Script (Pure JS, zero vulnerability, CSP-compliant) -->
    <script>
    (function() {
        const homeUrl = <?= json_encode($homeUrl) ?>;

        // Toast Helper
        function showToast(message, type = 'info') {
            const container = document.getElementById('toastContainer');
            if (!container) return;
            const toast = document.createElement('div');
            toast.className = 'pointer-events-auto flex items-center gap-2 rounded-xl border border-white/10 bg-slate-900/95 px-4 py-3 text-xs text-white shadow-2xl backdrop-blur-xl transition transform duration-300 translate-y-2 opacity-0';
            
            let icon = '<i class="fa-solid fa-circle-check text-emerald-400"></i>';
            if (type === 'warn') icon = '<i class="fa-solid fa-triangle-exclamation text-amber-400"></i>';
            if (type === 'error') icon = '<i class="fa-solid fa-circle-xmark text-rose-400"></i>';
            
            toast.innerHTML = icon + '<span>' + message + '</span>';
            container.appendChild(toast);
            
            requestAnimationFrame(() => {
                toast.classList.remove('translate-y-2', 'opacity-0');
            });

            setTimeout(() => {
                toast.classList.add('opacity-0', 'translate-y-2');
                setTimeout(() => toast.remove(), 300);
            }, 3500);
        }

        // Generic Button Actions
        document.querySelectorAll('[data-action]').forEach(function(btn) {
            btn.addEventListener('click', function() {
                const action = this.getAttribute('data-action');
                if (action === 'back') {
                    if (window.history.length > 1) {
                        window.history.back();
                    } else {
                        window.location.href = homeUrl;
                    }
                } else if (action === 'reload') {
                    window.location.reload();
                }
            });
        });

        // Copy Incident Ticket Button
        const copyBtn = document.getElementById('copyIncidentBtn');
        if (copyBtn) {
            copyBtn.addEventListener('click', function() {
                const ticket = this.getAttribute('data-incident');
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(ticket).then(() => {
                        showToast('Kode Tiket Insiden (' + ticket + ') berhasil disalin ke clipboard!', 'info');
                    }).catch(() => {
                        showToast('Kode Tiket: ' + ticket, 'info');
                    });
                } else {
                    showToast('Kode Tiket: ' + ticket, 'info');
                }
            });
        }

        // 503 Maintenance Auto-Refresh Timer
        const countdownEl = document.getElementById('countdownTimer');
        const toggleRefreshBtn = document.getElementById('toggleAutoRefreshBtn');
        if (countdownEl) {
            let timeLeft = 30;
            let isPaused = false;
            const timer = setInterval(() => {
                if (!isPaused) {
                    timeLeft--;
                    countdownEl.textContent = timeLeft;
                    if (timeLeft <= 0) {
                        clearInterval(timer);
                        window.location.reload();
                    }
                }
            }, 1000);

            if (toggleRefreshBtn) {
                toggleRefreshBtn.addEventListener('click', function() {
                    isPaused = !isPaused;
                    if (isPaused) {
                        this.innerHTML = '<i class="fa-solid fa-play"></i> Lanjutkan Hitung Mundur';
                        showToast('Penyegaran otomatis dijeda.', 'warn');
                    } else {
                        this.innerHTML = '<i class="fa-solid fa-pause"></i> Jeda Hitung Mundur';
                        showToast('Penyegaran otomatis dilanjutkan.', 'info');
                    }
                });
            }
        }

        // 429 Cooldown Timer
        const cooldownEl = document.getElementById('cooldownTimer');
        const cooldownBar = document.getElementById('cooldownBar');
        const cooldownReloadBtn = document.getElementById('cooldownReloadBtn');
        if (cooldownEl && cooldownReloadBtn) {
            let total = 45;
            let current = total;
            const coolTimer = setInterval(() => {
                current--;
                cooldownEl.textContent = current;
                if (cooldownBar) {
                    const pct = Math.max(0, (current / total) * 100);
                    cooldownBar.style.width = pct + '%';
                }
                if (current <= 0) {
                    clearInterval(coolTimer);
                    cooldownReloadBtn.removeAttribute('disabled');
                    cooldownReloadBtn.classList.remove('bg-slate-800', 'text-slate-500', 'cursor-not-allowed');
                    cooldownReloadBtn.classList.add('bg-gradient-to-r', 'from-emerald-600', 'to-teal-600', 'text-white', 'cursor-pointer');
                    cooldownReloadBtn.innerHTML = '<i class="fa-solid fa-rotate-right"></i> Coba Akses Sekarang';
                    showToast('Batas jeda telah berakhir! Anda dapat mencoba kembali sekarang.', 'info');
                }
            }, 1000);
        }

        // 408 / 504 Network Ping Test
        const testPingBtn = document.getElementById('testPingBtn');
        const pingStatusBadge = document.getElementById('pingStatusBadge');
        const pingStatusText = document.getElementById('pingStatusText');
        if (testPingBtn && pingStatusBadge && pingStatusText) {
            testPingBtn.addEventListener('click', function() {
                pingStatusBadge.className = 'text-[11px] px-2.5 py-0.5 rounded-full bg-yellow-500/20 text-yellow-300 font-semibold';
                pingStatusBadge.textContent = 'Memeriksa...';
                pingStatusText.textContent = 'Mengukur kestabilan koneksi internet perangkat Anda...';

                setTimeout(() => {
                    const isOnline = navigator.onLine;
                    if (isOnline) {
                        pingStatusBadge.className = 'text-[11px] px-2.5 py-0.5 rounded-full bg-emerald-500/20 text-emerald-300 font-semibold';
                        pingStatusBadge.textContent = 'Koneksi Stabil (Online)';
                        pingStatusText.textContent = 'Perangkat Anda terhubung normal ke internet. Kendala kemungkinan ada pada beban server atau transmisi berkas.';
                        showToast('Koneksi internet Anda terdeteksi normal!', 'info');
                    } else {
                        pingStatusBadge.className = 'text-[11px] px-2.5 py-0.5 rounded-full bg-rose-500/20 text-rose-300 font-semibold';
                        pingStatusBadge.textContent = 'Terputus (Offline)';
                        pingStatusText.textContent = 'Perangkat Anda kehilangan akses internet. Mohon periksa kembali jaringan Wi-Fi atau paket data seluler Anda.';
                        showToast('Perangkat Anda sedang offline!', 'error');
                    }
                }, 750);
            });
        }

        // Global Keyboard Shortcuts (ESC = Back, R = Reload)
        window.addEventListener('keydown', function(e) {
            if (e.target && (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA')) {
                return;
            }
            if (e.key === 'Escape') {
                if (window.history.length > 1) {
                    window.history.back();
                } else {
                    window.location.href = homeUrl;
                }
            } else if ((e.key === 'r' || e.key === 'R') && !e.ctrlKey && !e.metaKey) {
                window.location.reload();
            }
        });
    })();
    </script>
</body>
</html>
<?php
}
