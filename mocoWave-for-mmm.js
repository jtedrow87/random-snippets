var waveMemory = [], currentMp3 = null, greenBars = '#30af74', whiteBars = '#FFFFFF', timerYo = null, autoPlay = false, lastKnownVolume = 1;

if(!onMobile()) {
    var wavesurfer = Object.create(WaveSurfer);
    wavesurfer.init({
        container: '#waveform',
        waveColor: whiteBars,
        progressColor: greenBars,
        barWidth: 2,
        skipLength: 4,
        backend: 'MediaElement'
    });
    wavesurfer.on('finish', function () {

    });
    wavesurfer.on('ready', function () {

        jQuery('html').unbind('keydown').on('keydown', function (key) {
            switch (key.keyCode) {
                case 37:
                    wavesurfer.skipBackward();
                    break;
                case 39:
                    wavesurfer.skipForward();
                    break;
                case 32:
                    var tb = false;
                    jQuery('input,textarea').each(function(){
                        if(jQuery(this).is(':focus')){
                            tb = true;
                        }
                    });
                    if(!tb) {
                        if (key.target == document.body) {
                            key.preventDefault();
                        }
                        if (wavesurfer.isPlaying()) {
                            wavesurfer.pause();
                            clearInterval(timerYo);
                        } else {
                            wavesurfer.play();
                            timerYo = setInterval(refreshTimer, 500);
                        }
                    }
                    break;
            }
        });

        jQuery('.audio-play').unbind('click').on('click', function () {
            wavesurfer.play();
            jQuery(this).hide();
            jQuery('.audio-pause').show();
            timerYo = setInterval(refreshTimer, 500);
        });

        jQuery('.pause-song').unbind('click').on('click', function (e) {
            e.preventDefault();
            wavesurfer.pause();
            jQuery(this).hide();
            jQuery('.audio-pause').hide();
            jQuery('.play-song,.audio-play').show();
            clearInterval(timerYo);
        });

        jQuery('.audio-pause').unbind('click').on('click', function () {
            wavesurfer.pause();
            jQuery(this).hide();
            jQuery('.audio-play').show();
            clearInterval(timerYo);
        });
        jQuery('.audio-mute').unbind('click').on('click', function () {
            wavesurfer.toggleMute();
            jQuery(this).hide();
            jQuery('.audio-unmute').show();
        });
        jQuery('.audio-unmute').unbind('click').on('click', function () {
            wavesurfer.toggleMute();
            jQuery(this).hide();
            jQuery('.audio-mute').show();
        });

        if (autoPlay) {
            wavesurfer.play();
            jQuery('.audio-play').hide();
            jQuery('.audio-pause').show();
            timerYo = setInterval(refreshTimer, 500);
        }
    });

    wavesurfer.on('seek', function () {
        jQuery('.currentTime').text(convertTime(wavesurfer.getCurrentTime()));
    });

    setInterval(function () {
        var timerWidth = jQuery('.time'),
            progressWidth = jQuery('#waveform > wave > wave').width();

        if ((timerWidth.outerWidth() + 10) < progressWidth) {
            //timerWidth.animate({left: (progressWidth - timerWidth.outerWidth() - 10) + 'px'});
            timerWidth.css({left: (progressWidth - timerWidth.outerWidth() - 10) + 'px'});
        }
    }, 1);
}
function refreshTimer(){
    jQuery('.currentTime').text(convertTime(wavesurfer.getCurrentTime()));
    jQuery('.duration').text(convertTime(wavesurfer.getDuration()));
}
function convertTime (input) {
    if (!input) {
        return "00:00";
    }

    var minutes = Math.floor(input / 60);
    var seconds = Math.ceil(input) % 60;

    return (minutes < 10 ? '0' : '')
        + minutes
        + ":"
        + (seconds < 10 ? '0' : '') + seconds;
}
function setWaveForm(obj,peaks){
    if(typeof obj === 'string'){
        obj = {
            mp3: obj
        }
    }
    var mp3 = obj.mp3.replace('http://mirrormirrormusic.com','').replace('http://test.mirrormirrormusic.com','');

    if(mp3 !== audioFile){
        audioFile = mp3;
        jQuery('.jp-seek-bar').animate({
            'background-position-y': '70px'
        });
    }

    if(!peaks) {
        wavesurfer.load(audioFile);
    }else{
        wavesurfer.load(audioFile, peaks.left);
    }
    if(lastKnownVolume > 1){
        lastKnownVolume = (lastKnownVolume / 100);
    }
    wavesurfer.setVolume(lastKnownVolume);
    return;
    //jQuery('#myAudio').attr('src',audioFile);
    //startAudio();

    currentMp3 = mp3;
    if(undefined === waveMemory[currentMp3]) {
        jQuery.get('/wp-content/themes/child-theme_sura-web-app-theme/classes/mp3json.php', {
            file: mp3,
            width: jQuery(".audio-player .player-progress").width()
        }, function (aj) {
            var ab = aj.left;

            MoCoWaveForm.loadBuffer(null, {
                canvas_width: jQuery(".audio-player .player-progress").width(),
                canvas_height: 55,
                wave_color: whiteBars,
                buffer_memory: ab,
                onComplete: function (png, pixels, buffer) {
                    whiteImage = png;

                   jQuery('.player-progress').css('backgroundImage', 'url(' + whiteImage + ')').animate({
                        'background-position-y': '14px',
                        'background-repeat' : 'no-repeat'
                    });
                    waveMemory[currentMp3] = {
                        data: buffer,
                        width: jQuery(".audio-player .player-progress").width()
                    };
                    MoCoWaveForm.loadBuffer(null, {
                        canvas_width: jQuery(".audio-player .player-progress").width(),
                        canvas_height: 55,
                        wave_color: greenBars,
                        buffer_memory: buffer,
                        onComplete: function (png, pixels, buffer) {
                            greenImage = png;
                            jQuery('.jp-play-bar').css('backgroundImage', 'url(' + greenImage + ')').animate({
                                'background-position-y': '14px',
                                'background-repeat' : 'no-repeat',
                                'background-size': jQuery(".audio-player .player-progress").width() + 'px 55px'
                            });
                            MoCoWaveForm.clearBuffer();
                        }
                    });
                }
            });

        });
    }else{
        MoCoWaveForm.loadBuffer(null, {
            canvas_width: jQuery(".audio-player .player-progress").width(),
            canvas_height: 55,
            wave_color: whiteBars,
            buffer_memory: waveMemory[currentMp3].data,
            onComplete: function (png, pixels, buffer) {
                whiteImage = png;

                jQuery('.player-progress').css('backgroundImage', 'url(' + whiteImage + ')').animate({
                    'background-position-y': '14px',
                    'background-repeat' : 'no-repeat'
                });
                MoCoWaveForm.loadBuffer(null, {
                    canvas_width: jQuery(".audio-player .player-progress").width(),
                    canvas_height: 55,
                    wave_color: greenBars,
                    buffer_memory: buffer,
                    onComplete: function (png, pixels, buffer) {
                        greenImage = png;
                        jQuery('.jp-play-bar').css('backgroundImage', 'url(' + greenImage + ')').animate({
                            'background-position-y': '14px',
                            'background-size': jQuery(".audio-player .player-progress").width() + 'px 55px',
                            'background-repeat' : 'no-repeat'
                        });
                        MoCoWaveForm.clearBuffer();
                    }
                });
            }
        });
    }
}



var MoCoWaveForm = {

    settings : {
        canvas_width: 453,
        canvas_height: 55,
        bar_width: 3,
        bar_gap : 0.2,
        wave_color: "#FFF",
        past_color: "#30af74",
        buffer_memory: null,
        buffer_id: null,
        current_status: 0,
        onComplete: function(png, pixels) {}
    },

    loadBuffer: function(file, options) {
        this.settings.canvas = document.createElement('canvas');
        this.settings.context = this.settings.canvas.getContext('2d');
        this.settings.canvas.width = (options.canvas_width !== undefined) ? parseInt(options.canvas_width) : this.settings.canvas_width;
        this.settings.canvas.height = (options.canvas_height !== undefined) ? parseInt(options.canvas_height) : this.settings.canvas_height;
        this.settings.wave_color = (options.wave_color !== undefined) ? options.wave_color : this.settings.wave_color;
        this.settings.past_color = (options.past_color !== undefined) ? options.past_color : this.settings.past_color;
        this.settings.current_status = (options.current_status !== undefined) ? options.current_status : this.settings.current_status;
        this.settings.buffer_id = (options.buffer_id !== undefined) ? options.buffer_id : this.settings.buffer_id;
        this.settings.buffer_memory = (options.buffer_memory !== undefined) ? options.buffer_memory : this.settings.buffer_memory;
        if(null !== file && file !== '' && file !== undefined){
            this.settings.buffer_memory = null;
        }
        this.settings.bar_width = (options.bar_width !== undefined) ? parseInt(options.bar_width) : this.settings.bar_width;
        this.settings.bar_gap = (options.bar_gap !== undefined) ? parseFloat(options.bar_gap) : this.settings.bar_gap;
        this.settings.onComplete = (options.onComplete !== undefined) ? options.onComplete : this.settings.onComplete;

        MoCoWaveForm.skipExtraction(this.settings.buffer_memory);
    },

    skipExtraction: function(buffer){
        this.settings.buffer_memory = buffer;
        var sections = this.settings.canvas.width;

        var len = Math.floor(buffer.length / sections);
        var maxHeight = this.settings.canvas.height;
        var vals = [];

        for (var i = 0; i < sections; i += this.settings.bar_width) {
            vals.push(this.bufferMeasure(i * len, len, buffer) * 10000);
        }

        for (var j = 0; j < sections; j += this.settings.bar_width) {
            var scale = maxHeight / vals.max();
            var val = this.bufferMeasure(j * len, len, buffer) * 10000;
            val *= scale;
            val += 1;
            this.drawBar(j, val);
        }

        this.settings.onComplete(this.settings.canvas.toDataURL('image/png'),
            this.settings.context.getImageData(0, 0, this.settings.canvas.width, this.settings.canvas.height),
            this.settings.buffer_memory);

        this.settings.context.clearRect(0, 0, this.settings.canvas.width, this.settings.canvas.height);
    },

    bufferMeasure: function(position, length, data) {
        var sum = 0.0;
        for (var i = position; i <= (position + length) - 1; i++) {
            sum += Math.pow(data[i], 2);
        }
        return Math.sqrt(sum / data.length);
    },

    drawBar: function(i, h) {

        this.settings.context.fillStyle = this.settings.wave_color;

        var w = this.settings.bar_width;
        if (this.settings.bar_gap !== 0) {
            w *= Math.abs(1 - this.settings.bar_gap);
        }
        var x = i + (w / 2),
            y = this.settings.canvas.height - h;

        if(Math.ceil((x / this.settings.canvas.width) * 100) <= Math.ceil(this.settings.current_status)){
            this.settings.context.fillStyle = this.settings.past_color;
        }

        this.settings.context.fillRect(x, y, w, h);
    },

    clearBuffer: function(){
        this.settings.buffer_memory = null;
    }
};

function preloadAudioBuffer(url, callback){
    if(undefined === waveMemory[url]) {
        jQuery.get('/wp-content/themes/child-theme_sura-web-app-theme/classes/mp3json.php', {
            file: url.replace('http://mirrormirrormusic.com','').replace('http://test.mirrormirrormusic.com',''),
            width: 2000
        }, function (aj) {
            waveMemory[aj.file] = {
                left: aj.left,
                width: jQuery(".audio-player .player-progress").width()
            };
            callback();
        });
    }
}

function onMobile(){
    //return false;
    return (jQuery(window).width() < 1025);
}
