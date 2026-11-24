/* Sons de notification (Web Audio : aucun fichier audio). window.codcrmSound.play(nom, volume 0-100). */
(function () {
    'use strict';
    var ctx = null;
    function audio() {
        if (!ctx) {
            var C = window.AudioContext || window.webkitAudioContext;
            if (!C) { return null; }
            ctx = new C();
        }
        return ctx;
    }
    // Une note : fréquence, début (s), durée (s), forme d'onde, volume relatif.
    function tone(c, out, freq, at, dur, type, gain) {
        var o = c.createOscillator(), g = c.createGain();
        o.type = type; o.frequency.value = freq;
        g.gain.setValueAtTime(0.0001, at);
        g.gain.exponentialRampToValueAtTime(Math.max(0.0002, gain), at + 0.01);
        g.gain.exponentialRampToValueAtTime(0.0001, at + dur);
        o.connect(g); g.connect(out);
        o.start(at); o.stop(at + dur + 0.05);
    }
    var SOUNDS = {
        bell: function (c, out, t) { tone(c, out, 880, t, 0.9, 'sine', 0.9); tone(c, out, 1760, t, 0.5, 'sine', 0.35); tone(c, out, 2640, t, 0.25, 'sine', 0.15); },
        chime: function (c, out, t) { tone(c, out, 523, t, 0.35, 'sine', 0.8); tone(c, out, 659, t + 0.16, 0.35, 'sine', 0.8); tone(c, out, 784, t + 0.32, 0.6, 'sine', 0.8); },
        beep: function (c, out, t) { tone(c, out, 1000, t, 0.16, 'square', 0.25); },
        double: function (c, out, t) { tone(c, out, 880, t, 0.12, 'square', 0.25); tone(c, out, 880, t + 0.2, 0.12, 'square', 0.25); }
    };
    window.codcrmSound = {
        names: Object.keys(SOUNDS),
        /** Le navigateur autorise-t-il le son (après une interaction de l'utilisateur) ? */
        ready: function () { var c = audio(); return !!c && c.state === 'running'; },
        unlock: function () {
            var c = audio();
            if (c && c.state === 'suspended') { return c.resume().then(function () { return c.state === 'running'; }); }
            return Promise.resolve(!!c);
        },
        play: function (name, volume) {
            var c = audio(), fn = SOUNDS[name];
            if (!c || !fn || c.state !== 'running') { return false; }
            var out = c.createGain();
            out.gain.value = Math.max(0, Math.min(1, (volume == null ? 70 : volume) / 100));
            out.connect(c.destination);
            fn(c, out, c.currentTime + 0.02);
            window.codcrmSound.plays = (window.codcrmSound.plays || 0) + 1; // compteur (tests)
            return true;
        }
    };
})();
