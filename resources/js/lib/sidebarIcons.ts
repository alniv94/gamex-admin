import addPlayer from '../../assets/legacy/sidebar/3d-add-user-male.png';
import investor from '../../assets/legacy/sidebar/3d-administrator-male.png';
import basketball from '../../assets/legacy/sidebar/3d-basketball.png';
import transaction from '../../assets/legacy/sidebar/3d-bill.png';
import payout from '../../assets/legacy/sidebar/3d-cash-in-hand.png';
import tokens from '../../assets/legacy/sidebar/3d-coins.png';
import analytics from '../../assets/legacy/sidebar/3d-combo-chart.png';
import users from '../../assets/legacy/sidebar/3d-conference-call--v1.png';
import teams from '../../assets/legacy/sidebar/3d-conference.png';
import documents from '../../assets/legacy/sidebar/3d-documents.png';
import settings from '../../assets/legacy/sidebar/3d-gear--v1.png';
import outlet from '../../assets/legacy/sidebar/3d-home.png';
import menu from '../../assets/legacy/sidebar/3d-menu.png';
import cashOut from '../../assets/legacy/sidebar/3d-money-bag.png';
import cashIn from '../../assets/legacy/sidebar/3d-money-transfer.png';
import activePlayers from '../../assets/legacy/sidebar/3d-online.png';
import progress from '../../assets/legacy/sidebar/3d-positive-dynamic.png';
import prize from '../../assets/legacy/sidebar/3d-prize.png';
import bets from '../../assets/legacy/sidebar/3d-receive-cash.png';
import settlement from '../../assets/legacy/sidebar/3d-sales-performance.png';
import sms from '../../assets/legacy/sidebar/3d-speech-bubble-with-dots.png';
import schedule from '../../assets/legacy/sidebar/3d-tear-off-calendar.png';
import trophy from '../../assets/legacy/sidebar/3d-trophy.png';
import players from '../../assets/legacy/sidebar/3d-user-group-man-man.png';
import commission from '../../assets/legacy/sidebar/fluency-cash-in-hand.png';
import referral from '../../assets/legacy/sidebar/fluency-share--v2.png';

// Reuse the Icons8 images from admin-portal's NavbarLinks.vue, bundled locally.
export const sidebarIcons = {
    activePlayers,
    addPlayer,
    analytics,
    basketball,
    bets,
    cashIn,
    cashOut,
    commission,
    documents,
    investor,
    menu,
    outlet,
    payout,
    players,
    prize,
    progress,
    referral,
    schedule,
    settings,
    settlement,
    sms,
    teams,
    tokens,
    transaction,
    trophy,
    users,
};

export const endingReportIcons: Record<string, string> = {
    bets,
    'game-card': documents,
    game: basketball,
    progress,
    transactional: transaction,
    'cash-in': cashIn,
    'cash-out': cashOut,
    cards: documents,
    payouts: payout,
    'top-winning-combination': prize,
    'master-agents-token': tokens,
};

export const pagcorReportIcons: Record<string, string> = {
    'ending-game': basketball,
    'lucky-four-game': basketball,
    'ending-progress': progress,
    'lucky-four-progress': progress,
    transactional: transaction,
};
