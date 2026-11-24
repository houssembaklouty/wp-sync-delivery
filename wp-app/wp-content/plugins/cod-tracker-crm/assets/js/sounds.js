/* Sons de notification (Web Audio : aucun fichier audio). window.codcrmSound.play(nom, volume 0-100). */
(function () {
    'use strict';
    var ctx = null, elems = {}, fileOk = {};
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
        names: Object.keys(SOUNDS).concat(Object.keys(window.codcrmSoundFiles || {})),
        /** Le navigateur autorise-t-il le son (après une interaction de l'utilisateur) ? */
        ready: function () { var c = audio(); return !!c && c.state === 'running'; },
        fileReady: function (k) { return !!fileOk[k]; },
        unlock: function () {
            var c = audio();
            // Fichiers audio : une lecture muette pendant le geste de l'utilisateur les autorise ensuite.
            var files = window.codcrmSoundFiles || {};
            Object.keys(files).forEach(function (k) {
                var a = elems[k] || (elems[k] = new Audio(files[k]));
                a.preload = 'auto'; a.muted = true;
                var pr = a.play();
                if (pr && pr.then) { pr.then(function () { a.pause(); a.currentTime = 0; a.muted = false; fileOk[k] = true; }).catch(function () { a.muted = false; }); }
            });
            if (c && c.state === 'suspended') { return c.resume().then(function () { return c.state === 'running'; }).catch(function () { return false; }); }
            return Promise.resolve(!!c);
        },
        play: function (name, volume) {
            var files = window.codcrmSoundFiles || {}, vol = Math.max(0, Math.min(1, (volume == null ? 70 : volume) / 100));
            if (files[name]) { // fichier audio (mp3) du plugin
                var a = elems[name] || (elems[name] = new Audio(files[name]));
                a.muted = false; a.volume = vol; a.currentTime = 0;
                var pr = a.play();
                if (pr && pr.then) {
                    pr.then(function () { window.codcrmSound.plays = (window.codcrmSound.plays || 0) + 1; })
                      .catch(function () { window.codcrmSound.play('bell', volume); }); // refusé : repli sur la clochette.
                } else { window.codcrmSound.plays = (window.codcrmSound.plays || 0) + 1; }
                return true;
            }
            var c = audio(), fn = SOUNDS[name];
            if (!c || !fn) { return false; }
            if (c.state !== 'running') {
                // La page a peut-être déjà reçu un clic (activation) : on tente de reprendre le contexte audio.
                c.resume().then(function () { if (c.state === 'running') { window.codcrmSound.play(name, volume); } }).catch(function () { /* bloqué */ });
                return false;
            }
            var out = c.createGain();
            out.gain.value = Math.max(0, Math.min(1, (volume == null ? 70 : volume) / 100));
            out.connect(c.destination);
            fn(c, out, c.currentTime + 0.02);
            window.codcrmSound.plays = (window.codcrmSound.plays || 0) + 1; // compteur (tests)
            return true;
        }
    };
})();
