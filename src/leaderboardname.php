<?php
// leaderboardname.php -- Peteramati leaderboard pseudonyms
// Peteramati is Copyright (c) 2006-2026 Eddie Kohler and others
// See LICENSE for open-source distribution terms

/** Generates and assigns the memorable pseudonyms under which students appear
 * on leaderboards. A name is `ANIMAL-AGENT`, such as `giraffe-enjoyer` or
 * `platypus-knitter`, with a number appended when the plain combination is
 * already taken. */
class LeaderboardName {
    /** @var list<string> */
    static public $animals = [
        "aardvark", "albatross", "alpaca", "anteater", "antelope", "armadillo", "axolotl", "badger",
        "bandicoot", "barnacle", "barracuda", "basilisk", "beetle", "bison", "bittern", "blackbird",
        "bluejay", "bobcat", "bullfrog", "bumblebee", "caiman", "camel", "capybara", "caracal",
        "cardinal", "caribou", "cassowary", "catfish", "chameleon", "cheetah", "chinchilla", "chipmunk",
        "cicada", "civet", "coati", "condor", "cormorant", "coyote", "crayfish", "cuttlefish",
        "dingo", "dolphin", "dormouse", "dragonfly", "dugong", "echidna", "egret", "elephant",
        "elk", "emu", "falcon", "fennec", "ferret", "finch", "firefly", "flamingo",
        "flounder", "gannet", "gazelle", "gecko", "gerbil", "gibbon", "giraffe", "goldfinch",
        "goshawk", "grackle", "grouper", "guanaco", "guppy", "hamster", "hedgehog", "heron",
        "hornbill", "hummingbird", "ibex", "ibis", "iguana", "impala", "jackal", "jackdaw",
        "jaguar", "jellyfish", "jerboa", "kakapo", "kestrel", "kingfisher", "kinkajou", "koala",
        "kookaburra", "lamprey", "lapwing", "lemming", "lemur", "leopard", "limpet", "llama",
        "lobster", "loris", "lynx", "macaque", "magpie", "mallard", "manatee", "mandrill",
        "mantis", "marmoset", "marmot", "marten", "meerkat", "mongoose", "moorhen", "mudskipper",
        "narwhal", "newt", "nightjar", "numbat", "nuthatch", "ocelot", "octopus", "okapi",
        "opossum", "orangutan", "oriole", "osprey", "ostrich", "otter", "pangolin", "panther",
        "parakeet", "partridge", "pelican", "penguin", "petrel", "pheasant", "pika", "platypus",
        "plover", "porcupine", "porpoise", "possum", "puffin", "puma", "quail", "quetzal",
        "quokka", "quoll", "raccoon", "raven", "reindeer", "rhinoceros", "roadrunner", "salamander",
        "sandpiper", "sawfish", "scallop", "seahorse", "serval", "shearwater", "shrew", "siskin",
        "skink", "skylark", "sloth", "snapper", "songbird", "sparrow", "spoonbill", "squid",
        "squirrel", "starling", "stingray", "stoat", "stork", "sturgeon", "sunfish", "swift",
        "tamarin", "tanager", "tapir", "tarsier", "terrapin", "thrush", "tortoise", "toucan",
        "trout", "turtle", "umbrellabird", "urchin", "vicuna", "viper", "vireo", "vole",
        "wallaby", "walrus", "warbler", "wombat", "wren", "yak", "zebra", "zebu"
    ];

    /** @var list<string> */
    static public $agents = [
        "advocate", "alchemist", "allocator", "alpinist", "ambassador", "analyst", "angerer", "annotator",
        "apprentice", "archer", "archivist", "arranger", "artisan", "astronomer", "auditor", "author",
        "baker", "bamboozler", "bandleader", "banjoist", "befuddler", "believer", "benefactor", "bewilderer",
        "bibliophile", "boatwright", "boggler", "botanist", "bouncer", "brewer", "builder", "cajoler",
        "calligrapher", "camper", "canoeist", "cartographer", "carver", "cataloguer", "caterer", "champion",
        "chandler", "chronicler", "cobbler", "colorist", "commentator", "compiler", "complicator", "composer",
        "conductor", "confounder", "confuser", "conjoiner", "conservator", "copyist", "coxswain", "crafter",
        "critic", "curator", "cyclist", "dabbler", "dancer", "dawdler", "debugger", "defender",
        "delegate", "discombobulator", "doodler", "drummer", "editor", "educator", "embroiderer", "emissary",
        "etcher", "fiddler", "fidgeter", "finisher", "flummoxer", "flutist", "folklorist", "forager",
        "forester", "founder", "fumbler", "gallivanter", "gardener", "giggler", "gleaner", "grafter",
        "grumbler", "guide", "harvester", "hasher", "herald", "herbalist", "historian", "hooper",
        "hopper", "horticulturist", "illustrator", "improviser", "inventor", "investigator", "juggler", "jumbler",
        "keeper", "kibitzer", "knitter", "lexicographer", "librarian", "linguist", "lithographer", "looper",
        "mason", "matcher", "meanderer", "meddler", "mediator", "mentor", "meteorologist", "miller",
        "mimic", "mixer", "modeler", "muddler", "mumbler", "muralist", "narrator", "navigator",
        "networker", "nitpicker", "noodler", "nudger", "observer", "optimist", "organizer", "overthinker",
        "painter", "paleontologist", "partisan", "patron", "perplexer", "philosopher", "photographer", "pilgrim",
        "pioneer", "planner", "planter", "ponderer", "preserver", "printer", "procrastinator", "producer",
        "professor", "puzzler", "quibbler", "quilter", "ranger", "reader", "rearranger", "reciter",
        "recorder", "refactorer", "referee", "reformer", "researcher", "restorer", "scholar", "scrambler",
        "scribbler", "scribe", "sculptor", "shepherd", "shuffler", "sketcher", "skipper", "smith",
        "snoozer", "solver", "sorter", "spinner", "splitter", "squabbler", "stacker", "steward",
        "stonecutter", "strategist", "surveyor", "tactician", "tangler", "tinkerer", "toggler", "tracker",
        "trailblazer", "translator", "troubadour", "tumbler", "tuner", "unraveler", "untangler", "upholsterer",
        "voyager", "waffler", "wanderer", "weaver", "welder", "whittler", "wordsmith", "worrier",
        "yodeler", "zigzagger"
    ];

    /** More words. `generate()` never pairs a word from `$animals2` with
     * one from `$agents2`.
     * @var list<string> */
    static public $animals2 = [
        "beaver", "bonobo", "cockatoo", "peacock", "swallow", "woodpecker"
    ];

    /** @var list<string> */
    static public $agents2 = [
        "admirer", "aficionado", "collector", "connoisseur", "devotee", "enjoyer", "enthusiast", "expander",
        "explorer", "fancier", "hoarder", "hobbyist", "popper", "rummager", "sampler", "spelunker",
        "twiddler", "wiggler", "wrangler"
    ];

    /** @return string */
    static function generate() {
        $nan = count(self::$animals);
        $nag = count(self::$agents);
        do {
            $i = mt_rand(0, $nan + count(self::$animals2) - 1);
            $j = mt_rand(0, $nag + count(self::$agents2) - 1);
        } while ($i >= $nan && $j >= $nag);
        $a = $i < $nan ? self::$animals[$i] : self::$animals2[$i - $nan];
        $g = $j < $nag ? self::$agents[$j] : self::$agents2[$j - $nag];
        return "{$a}-{$g}";
    }

    /** Assign leaderboard names to every contact in `$cids` that lacks one.
     * @param list<int> $cids
     * @return array<int,string> map from contact ID to leaderboard name */
    static function assign(Conf $conf, $cids) {
        if (empty($cids)) {
            return [];
        }
        $names = [];
        $result = $conf->qe("select contactId, leaderboard_name from ContactInfo where contactId?a", $cids);
        $missing = [];
        while (($row = $result->fetch_row())) {
            if ((string) $row[1] === "") {
                $missing[] = (int) $row[0];
            } else {
                $names[(int) $row[0]] = $row[1];
            }
        }
        Dbl::free($result);
        foreach ($missing as $cid) {
            if (($n = self::assign_one($conf, $cid)) !== null) {
                $names[$cid] = $n;
            }
        }
        return $names;
    }

    /** @param int $cid
     * @return ?string */
    static function assign_one(Conf $conf, $cid) {
        // Retry on collision: first with a bare `animal-agent`, then with an
        // increasingly long numeric suffix.
        for ($try = 0; $try !== 20; ++$try) {
            $n = self::generate();
            if ($try >= 5) {
                $n .= "-" . mt_rand(2, $try >= 12 ? 999 : 99);
            }
            $result = $conf->ql("update ContactInfo set leaderboard_name=? where contactId=? and leaderboard_name is null", $n, $cid);
            if ($result && $result->affected_rows > 0) {
                Dbl::free($result);
                return $n;
            }
            Dbl::free($result);
            // either the name was taken, or someone else assigned one first
            $n = $conf->fetch_value("select leaderboard_name from ContactInfo where contactId=?", $cid);
            if ((string) $n !== "") {
                return $n;
            }
        }
        return null;
    }
}
