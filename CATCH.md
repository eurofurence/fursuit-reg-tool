# Fursuit Catch-Em-All Game System Documentation

## System Overview

The Catch-Em-All system is a **mobile-first gamified web app** for furry conventions. Fursuiters wear badges with unique 5-character codes, and attendees can "catch" them by entering these codes into the game, similar to a scavenger hunt with rarity levels, achievements, and leaderboards.

## New Architecture (2025 Redesign)

### Domain-Driven Design Structure

```
app/Domain/CatchEmAll/
├── Controllers/GameController.php      # Main game logic
├── Models/
│   ├── UserCatch.php                   # Enhanced with rarity/points
│   └── UserAchievement.php             # Achievement tracking
├── Services/
│   ├── GameStatsService.php            # Stats calculation & caching
│   └── AchievementService.php          # Achievement logic
├── Enums/
│   ├── SpeciesRarity.php              # Common->Legendary rarity system
│   └── Achievement.php                 # Achievement definitions
└── CatchEmAllServiceProvider.php       # Service bindings
```

### Database Tables

-   `user_achievements` - Achievement progress and completion tracking
-   `user_catch_logs` - All catch attempts (includes anti-cheat detection)
-   `fursuits` - Fursuit records with `catch_em_all` boolean flag
-   `events` - Events with `catch_em_all_enabled` flag

### Game Features

-   **Rarity System**: Species are categorized as Common (1pt) → Uncommon (2pts) → Rare (5pts) → Epic (10pts) → Legendary (25pts)
-   **Achievement System**: 10 different achievements including Legendary Master, Speed Demon, Social Butterfly
-   **Mobile App Interface**: Bottom navigation with YouTube-style tab layout, Lucide icons, no emojis
-   **Tab-Based Navigation**: Leaderboard, Achievements, **Catch!** (center), Collection, Profile
-   **Real-time Progress**: Live stats, collection tracking, and leaderboard updates
-   **Anti-Cheat**: Automated detection of suspicious behavior patterns

### Routes (`/fcea/`)

-   `GET /` - Main game interface (`fcea.game`)
-   `POST /catch` - Submit catch code (`fcea.catch`)
-   `GET /collection` - Detailed collection view (`fcea.collection`)
-   `GET /achievements` - Achievement progress (`fcea.achievements`)

### UI/UX Design (2025 Mobile-First Redesign)

**Mobile App Navigation**:

-   **Bottom Tab Bar**: Fixed navigation like YouTube/Instagram with 5 tabs
-   **Central Catch Button**: Prominent, elevated with gradient background
-   **Lucide Icons**: Professional icon set instead of emojis
-   **Tab States**: Active tabs show colored background and icons

**Visual Design**:

-   **Clean Header**: App icon, title, and settings in top bar
-   **Card-Based Layout**: Each section in clean white cards with subtle shadows
-   **Gradient Accents**: Blue-to-purple gradients for primary actions
-   **Responsive Stats**: Visual progress bars, circular icons, grid layouts
-   **Touch-Friendly**: Large buttons, adequate spacing, tap feedback

**Content Organization**:

-   **Catch Tab**: Stats overview, code input, event filter (default active)
-   **Leaderboard Tab**: Top players with rank icons and points
-   **Achievements Tab**: Progress tracking with completion states
-   **Collection Tab**: Species collection with rarity indicators
-   **Profile Tab**: Future user profile section (placeholder)

## Recent Optimizations (Completed)

### 1. ✅ Fixed Event Selection Logic

-   **Solution**: Simplified logic to always default to current event (EF29)
-   **Location**: `DashboardController::index()` lines 36-43
-   **Impact**: Users now see current event leaderboard by default

### 2. ✅ Added Scheduled Ranking Updates

-   **Solution**: Created `RefreshFceaRankingsCommand` with scheduled execution
-   **Location**: `routes/console.php` - runs every 15 minutes during convention hours
-   **Impact**: Rankings stay fresh without requiring user actions

### 3. ✅ Improved Performance & Caching

-   **Solution**: Optimized cache keys, increased TTL, specific cache clearing
-   **Location**: `DashboardController` cache methods
-   **Impact**: Faster dashboard loads, reduced database queries

### 4. Current Architecture Limitations

-   **Issue**: Separate user/fursuit ranking systems sharing same table
-   **Location**: `UserCatchRanking` model and `DashboardController::refresh*` methods
-   **Future**: Consider splitting into separate models for better maintainability

## Key Files and Their Purpose

```
app/
├── Http/Controllers/FCEA/
│   └── DashboardController.php          # Main FCEA controller
├── Models/FCEA/
│   ├── UserCatch.php                    # Individual catch records
│   ├── UserCatchLog.php                 # Catch attempt logging
│   └── UserCatchRanking.php             # Rankings (users + fursuits)
├── Jobs/
│   └── UpdateRankingsJob.php            # Background ranking updates
└── Console/Commands/
    ├── FursuitCreateCatchCodeCommand.php # Generate catch codes
    └── RefreshFceaRankingsCommand.php    # Periodic ranking refresh

resources/js/Pages/FCEA/
└── Dashboard.vue                        # Main frontend interface

routes/
├── fcea.php                            # FCEA routes
└── console.php                         # Console commands/scheduling

config/
└── fcea.php                            # FCEA configuration

database/
├── seeders/FceaSeeder.php              # FCEA test data
└── factories/FCEA/UserCatchFactory.php # Test data factory
```

## Configuration

-   `FURSUIT_CATCH_CODE_LENGTH=5` - Length of catch codes
-   `FURSUIT_CATCH_ATTEMPTS_PER_MINUTE=20` - Rate limiting

## Optimization Opportunities

1. **Simplify Event Logic**: Always show current event data unless explicitly filtered
2. **Add Scheduled Jobs**: Periodic ranking updates via console scheduling
3. **Improve Caching**: Better cache keys and longer TTLs for stable data
4. **Optimize Queries**: Reduce N+1 queries and complex joins
5. **Separate Ranking Models**: Split user/fursuit rankings into separate tables
