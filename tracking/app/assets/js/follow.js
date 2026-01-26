/**
 * Follow Module
 *
 * Auto-centers map on followed member when their location updates.
 */

window.Follow = {
    init() {
        TrackingState.on('follow:started', (userId) => {
            const member = TrackingState.getMember(userId);
            if (member) {
                TrackingMap.panTo(member.lat, member.lng, 15);
            }
        });

        TrackingState.on('members:updated', (members) => {
            if (TrackingState.followingMember) {
                const member = members.find(m => m.user_id === TrackingState.followingMember);
                if (member) {
                    TrackingMap.panTo(member.lat, member.lng);
                }
            }
        });
    }
};
