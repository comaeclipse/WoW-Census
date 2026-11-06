<!-- ======= Backdrop scenery (original abstract artwork) ======= -->
<div class="backdrop" aria-hidden="true">
  <!-- Left: fel-fire / golden arcane storm -->
  <svg class="left" width="900" viewBox="0 0 900 1300" preserveAspectRatio="xMaxYMin slice">
    <defs>
      <radialGradient id="lg1" cx="85%" cy="18%" r="70%">
        <stop offset="0" stop-color="#d7ff6a"/>
        <stop offset=".12" stop-color="#5fd400"/>
        <stop offset=".35" stop-color="#1c4a06"/>
        <stop offset=".7" stop-color="#071204"/>
        <stop offset="1" stop-color="#000"/>
      </radialGradient>
      <radialGradient id="lg2" cx="70%" cy="22%" r="30%">
        <stop offset="0" stop-color="#fff6c0" stop-opacity=".95"/>
        <stop offset=".3" stop-color="#ffb000" stop-opacity=".55"/>
        <stop offset="1" stop-color="#ff6a00" stop-opacity="0"/>
      </radialGradient>
      <linearGradient id="fade" x1="0" x2="0" y1="0" y2="1">
        <stop offset=".55" stop-color="#000" stop-opacity="0"/>
        <stop offset="1" stop-color="#000"/>
      </linearGradient>
      <filter id="blur6"><feGaussianBlur stdDeviation="6"/></filter>
      <filter id="blur2"><feGaussianBlur stdDeviation="2"/></filter>
      <filter id="turb">
        <feTurbulence type="fractalNoise" baseFrequency=".012 .03" numOctaves="4" seed="7"/>
        <feColorMatrix values="0 0 0 0 .3  0 0 0 0 .9  0 0 0 0 .1  0 0 0 .9 -.35"/>
      </filter>
    </defs>
    <rect width="900" height="1300" fill="url(#lg1)"/>
    <rect width="900" height="900" filter="url(#turb)" opacity=".6"/>
    <!-- swirling energy arcs -->
    <g fill="none" stroke-linecap="round">
      <path d="M900 150 C 700 120, 560 260, 640 380 S 860 470, 760 600" stroke="#ffcf3a" stroke-width="14" opacity=".35" filter="url(#blur6)"/>
      <path d="M900 150 C 700 120, 560 260, 640 380 S 860 470, 760 600" stroke="#fff3b0" stroke-width="2.5" opacity=".9" filter="url(#blur2)"/>
      <path d="M880 260 C 650 260, 520 420, 690 520 S 900 640, 820 820" stroke="#9cff3a" stroke-width="10" opacity=".35" filter="url(#blur6)"/>
      <path d="M880 260 C 650 260, 520 420, 690 520 S 900 640, 820 820" stroke="#e5ffb0" stroke-width="2" opacity=".85"/>
      <path d="M760 90 C 600 200, 700 300, 560 420" stroke="#ffd75a" stroke-width="1.5" opacity=".7"/>
    </g>
    <circle cx="720" cy="240" r="210" fill="url(#lg2)"/>
    <!-- floating sparks -->
    <g fill="#fff6c0">
      <circle cx="640" cy="180" r="2.5"/><circle cx="700" cy="330" r="1.8"/><circle cx="790" cy="420" r="2.2"/>
      <circle cx="610" cy="470" r="1.5"/><circle cx="830" cy="210" r="1.6"/><circle cx="560" cy="300" r="1.2"/>
      <circle cx="745" cy="560" r="2"/><circle cx="680" cy="640" r="1.4"/>
    </g>
    <!-- silhouetted crags -->
    <path d="M0 980 L120 860 L180 900 L260 790 L330 860 L420 760 L520 870 L600 820 L700 900 L780 850 L900 910 L900 1300 L0 1300Z" fill="#040703"/>
    <path d="M300 1040 L420 930 L470 960 L560 880 L640 950 L740 900 L900 980 L900 1300 L300 1300Z" fill="#020302"/>
    <rect width="900" height="1300" fill="url(#fade)"/>
  </svg>

  <!-- Right: twisting nether / violet storm with floating crystals -->
  <svg class="right" width="900" viewBox="0 0 900 1300" preserveAspectRatio="xMinYMin slice">
    <defs>
      <radialGradient id="rg1" cx="20%" cy="20%" r="75%">
        <stop offset="0" stop-color="#ffb3ff"/>
        <stop offset=".1" stop-color="#c54bff"/>
        <stop offset=".35" stop-color="#4a0a7a"/>
        <stop offset=".7" stop-color="#10021c"/>
        <stop offset="1" stop-color="#000"/>
      </radialGradient>
      <linearGradient id="crys" x1="0" x2="1">
        <stop offset="0" stop-color="#2a0a40"/>
        <stop offset=".5" stop-color="#e6a6ff"/>
        <stop offset="1" stop-color="#3a0a5a"/>
      </linearGradient>
      <filter id="turb2">
        <feTurbulence type="fractalNoise" baseFrequency=".008 .02" numOctaves="4" seed="3"/>
        <feColorMatrix values="0 0 0 0 .8  0 0 0 0 .3  0 0 0 0 1  0 0 0 1 -.4"/>
      </filter>
      <filter id="glow"><feGaussianBlur stdDeviation="5"/></filter>
    </defs>
    <rect width="900" height="1300" fill="url(#rg1)"/>
    <rect width="900" height="900" filter="url(#turb2)" opacity=".55"/>
    <!-- lightning -->
    <g fill="none" stroke="#f6d8ff" stroke-linejoin="round">
      <path d="M40 0 L90 120 L60 140 L130 280 L110 300 L170 420" stroke-width="2.5"/>
      <path d="M40 0 L90 120 L60 140 L130 280 L110 300 L170 420" stroke-width="12" opacity=".35" filter="url(#glow)"/>
      <path d="M130 280 L200 320 L230 300" stroke-width="1.2" opacity=".8"/>
    </g>
    <!-- floating crystal spire -->
    <g transform="translate(220 330)">
      <ellipse cx="0" cy="40" rx="70" ry="16" fill="#d07aff" opacity=".35" filter="url(#glow)"/>
      <path d="M0 -160 L26 -20 L0 70 L-26 -20Z" fill="url(#crys)"/>
      <path d="M0 -160 L0 70" stroke="#fff" stroke-width=".8" opacity=".6"/>
      <circle cx="0" cy="-60" r="58" fill="none" stroke="#9a5aca" stroke-width="3" opacity=".7"/>
      <circle cx="0" cy="-60" r="70" fill="none" stroke="#6a2a9a" stroke-width="1.5" opacity=".6" stroke-dasharray="6 6"/>
    </g>
    <!-- floating rocks -->
    <g fill="#120420">
      <path d="M60 640 l60 -30 l80 10 l30 30 l-60 40 l-80 -10Z"/>
      <path d="M260 560 l40 -18 l50 8 l14 22 l-40 26 l-50 -8Z"/>
      <path d="M380 700 l30 -12 l40 6 l10 16 l-30 18 l-40 -6Z"/>
    </g>
    <path d="M0 920 L90 860 L160 900 L260 830 L360 890 L470 840 L600 900 L720 860 L900 920 L900 1300 L0 1300Z" fill="#06010a"/>
    <rect width="900" height="1300" fill="url(#fade)"/>
  </svg>
</div>
