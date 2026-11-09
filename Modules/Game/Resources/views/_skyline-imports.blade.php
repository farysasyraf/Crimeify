{{-- Skyline's 3D scene (skyline-scene.js) and Three.js, by name, for the page's modules (skyline.js) and scripts (live.js,
     live-host.js), each with its version, so a new one isn't kept from the browser's cache. Before any module loads. --}}
<script type="importmap">
{!! json_encode(['imports' => [
    'three' => versioned_asset('vendor/three/three.module.min.js'),
    'three/addons/controls/OrbitControls.js' => versioned_asset('vendor/three/addons/controls/OrbitControls.js'),
    'crimeify/skyline-scene' => versioned_asset('modules/game/skyline-scene.js'),
]], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) !!}
</script>
