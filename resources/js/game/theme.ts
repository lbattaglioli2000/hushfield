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
    /** Back walls stand behind everything on the floor, but over the doors that open in them. */
    walls: 0.6,
    /** Rugs lie on the floor: over doors, under everything that stands. */
    rugs: 0.75,
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

/** Placeholder furniture, until items have artwork: a tinted block on its footprint. */
export const FURNITURE = {
    /** How far the block stands in from the edges of its footprint, in tiles. */
    insetTiles: 0.1,
    /** For items that don't say how tall they are. */
    heightPx: 18,
    saturation: 0.35,
    lightness: 0.55,
    /** How much darker the left and right faces are than the top. */
    shade: [0.8, 0.65],
    /** The pale edge along the side a piece faces. */
    frontColor: 0xfbf7ee,
    frontWidthPx: 3,
    /** Like buildings: how see-through a piece turns while it hides the player's own avatar. */
    fadedAlpha: 0.45,
    fadeMs: 120,
    /** The outline of where the chosen piece would go, while decorating. */
    ghostColor: 0xffd27a,
} as const;

/** Placeholder buildings, until they have artwork: a tinted block with a door and lit windows. */
export const BUILDING = {
    saturation: 0.22,
    lightness: 0.52,
    /** The roof is the base colour; the walls are darker, the lit south-west face less so. */
    shade: [0.85, 0.6],
    windowColor: 0xffd27a,
    windowAlpha: 0.7,
    doorHeightPx: 40,
    /** How see-through a building turns while it hides the player's own avatar. */
    fadedAlpha: 0.4,
    fadeMs: 120,
} as const;

/** Placeholder back walls, until a style has artwork: plain tinted panels, one per tile of the back edges. */
export const WALL = {
    /** How tall the panels stand above the floor: about three tiles. */
    heightPx: 96,
    saturation: 0.25,
    lightness: 0.6,
    /** Like buildings' faces: the right wall faces the lower left, as their lit south-west face does; the left wall is in shade. */
    shade: { right: 0.9, left: 0.7 },
    /** A paler band along the top and a darker skirting board along the floor. */
    trimPx: 4,
    trimLighten: 1.15,
    skirtingPx: 6,
    skirtingDarken: 0.7,
    /** A doorway is open from the floor up to here, between narrow jambs. */
    doorHeightPx: 64,
    jambTiles: 0.12,
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
    /** The red ON AIR light beside the name while the resident is talking on voice. */
    onAirColor: 0xc8372d,
    onAirRadius: 3,
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

/** The rod, line and bobber, drawn as shapes until fishing has art. */
export const FISHING = {
    rodColor: 0x24160d,
    lineColor: 0xf4efe4,
    bobberColor: 0xc8372d,
    rippleColor: 0x9fb4c8,
    /** Matches the set's amber, so a bite reads like the signal cards. */
    biteColor: 0xf2b84b,
    catchColor: 0xc9d1d9,
    /** How high above the feet the rod is held, and how long it is, in pixels. */
    handY: 22,
    rodLength: 18,
    /** How far the bobber is pulled under while something bites. */
    biteDip: 4,
    /** How long reeling in takes to play out. */
    endMs: 650,
    /** How far out over the water the bobber lands, in tiles. */
    reachTiles: 1.6,
} as const;
