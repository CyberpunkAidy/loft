{{-- Mux Player for a ready video. $playback comes from VideoLibrary::playback(). No Mux Data cookies. --}}
<mux-player class="video-player" style="aspect-ratio: {{ $video->cssAspectRatio() }}; --media-accent-color: {{ $studio->accent() }}"
    playback-id="{{ $playback['playback_id'] }}"
    playback-token="{{ $playback['playback_token'] }}"
    thumbnail-token="{{ $playback['thumbnail_token'] }}"
    storyboard-token="{{ $playback['storyboard_token'] }}"
    stream-type="on-demand" accent-color="{{ $studio->accent() }}" disable-cookies
    metadata-video-id="loft-video-{{ $video->id }}" metadata-video-title="{{ $video->title }}"
    title="{{ $video->title }}"></mux-player>
<script src="https://cdn.jsdelivr.net/npm/@mux/mux-player@3.14.1/dist/mux-player.js" integrity="sha384-noxMZp3g5zWTJ/g+QoncaGB8Llo5Pdw8RUc1TOt14jk2h8zk7MjoqS4T1SwFujZM" crossorigin="anonymous" defer></script>
