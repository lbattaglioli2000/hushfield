/** Colours, type and layering shared by every scene. The React overlays use the same hex values. */
export const COLORS = {
    night: '#1d2330',
    paper: '#fbf7ee',
    cream: '#f4efe4',
    selfName: '#ffe9a8',
    defaultBody: '#cccccc',
} as const;

export const FONT_FAMILY = 'Instrument Sans, system-ui, sans-serif';

/** Ground below the cursor highlights, avatars above both, sorted by screen y. */
export const DEPTH = {
    ground: 0,
    hover: 1,
    marker: 2,
    avatars: 10,
} as const;

export const AVATAR = {
    nameFontSize: '12px',
    nameStrokeThickness: 3,
    /** The sprite's feet sit this far below the tile centre. */
    feetOffsetY: 4,
    idleAlpha: 0.45,
} as const;

export const BUBBLE = {
    fontSize: '13px',
    wrapWidth: 180,
    padding: 6,
    /** Space between the top of the avatar and the bottom of its bubble. */
    gapY: 20,
    fadeMs: 600,
    strokeAlpha: 0.4,
} as const;

export const CAMERA = {
    followLerp: 0.12,
    /** Lets the camera scroll this far past the map edges. */
    boundsPadding: 200,
} as const;
