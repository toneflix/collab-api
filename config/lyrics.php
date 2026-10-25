<?php

/*
 * Words the lyrics check looks for. Admins add more in the AI settings.
 * Matching ignores case and whole words only, so "class" never matches "ass".
 */
return [

    // Any of these marks a track explicit.
    'explicit' => [
        'fuck', 'fucks', 'fucked', 'fucker', 'fuckers', 'fuckin', 'fucking',
        'motherfucker', 'motherfuckers', 'motherfuckin', 'motherfucking',
        'shit', 'shits', 'shitty', 'bullshit',
        'bitch', 'bitches', 'asshole', 'assholes',
        'pussy', 'dick', 'dicks', 'cock', 'cunt',
        'nigga', 'niggas', 'whore', 'whores', 'hoe', 'hoes',
    ],

    // Any of these sends the track to the moderation queue for a person to check.
    'review' => [
        'nigger', 'niggers', 'faggot', 'faggots', 'fag', 'fags',
        'retard', 'retards', 'chink', 'chinks', 'spic', 'spics',
        'kike', 'kikes', 'tranny', 'trannies',
        'kill yourself', 'kill yourselves',
    ],

];
