/** Colours, type and layering shared by every scene. The React overlays use the same hex values. */
export const COLORS = {
    night: '#1d2330',
    paper: '#fbf7ee',
    cream: '#f4efe4',
    selfName: '#ffe9a8',
    defaultBody: '#cccccc',
} as const;

export const FONT_FAMILY = 'Instrument Sans, system-ui, sans-serif';

/** Ground and doors below the cursor highlights; furniture and avatars above, sorted by screen y; labels on top. */
export const DEPTH = {
    ground: 0,
    door: 0.5,
    hover: 1,
    marker: 2,
    avatars: 10,
    labels: 100_000,
} as const;

export const DOOR = {
    color: 0xffd27a,
    alpha: 0.45,
    labelFontSize: '13px',
    labelStrokeThickness: 3,
    labelGapY: 60,
} as const;

/** Placeholder furniture, until items have artwork: a tinted block on its tile. */
export const FURNITURE = {
    /** Share of a tile's diamond the block's footprint covers. */
    footprint: 0.7,
    heightPx: 18,
    saturation: 0.35,
    lightness: 0.55,
    /** How much darker the left and right faces are than the top. */
    shade: [0.8, 0.65],
} as const;

export const TRAVEL = {
    /** Fade out before leaving a room, and in once the next is drawn. */
    fadeMs: 220,
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
