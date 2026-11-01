/**
 * Every seat gets a friendly animal and a colour so kids can find themselves (and each other)
 * at a glance. Seats are 1-based.
 */
const AVATARS = [
    { emoji: '🐶', bg: 'bg-sun' },
    { emoji: '🐱', bg: 'bg-berry' },
    { emoji: '🐰', bg: 'bg-sky' },
    { emoji: '🦊', bg: 'bg-tangerine' },
    { emoji: '🐼', bg: 'bg-mint' },
    { emoji: '🐸', bg: 'bg-grape' },
    { emoji: '🐵', bg: 'bg-sky-soft' },
    { emoji: '🐧', bg: 'bg-berry-soft' },
];

export function seatAvatar(seat) {
    return AVATARS[(Math.max(1, seat ?? 1) - 1) % AVATARS.length];
}
