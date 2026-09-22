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
        "bandicoot", "barnacle", "barracuda", "basilisk", "beaver", "beetle", "bison", "bittern",
        "blackbird", "bluejay", "bobcat", "bonobo", "bullfrog", "bumblebee", "caiman", "camel",
        "capybara", "caracal", "cardinal", "caribou", "cassowary", "catfish", "chameleon", "cheetah",
        "chinchilla", "chipmunk", "cicada", "civet", "coati", "cockatoo", "condor", "cormorant",
        "coyote", "crayfish", "cuttlefish", "dingo", "dolphin", "dormouse", "dragonfly", "dugong",
        "echidna", "egret", "elephant", "elk", "emu", "falcon", "fennec", "ferret",
        "finch", "firefly", "flamingo", "flounder", "gannet", "gazelle", "gecko", "gerbil",
        "gibbon", "giraffe", "goldfinch", "goshawk", "grackle", "grouper", "guanaco", "guppy",
        "hamster", "hedgehog", "heron", "hornbill", "hummingbird", "ibex", "ibis", "iguana",
        "impala", "jackal", "jackdaw", "jaguar", "jellyfish", "jerboa", "kakapo", "kestrel",
        "kingfisher", "kinkajou", "koala", "kookaburra", "lamprey", "lapwing", "lemming", "lemur",
        "leopard", "limpet", "llama", "lobster", "loris", "lynx", "macaque", "magpie",
        "mallard", "manatee", "mandrill", "mantis", "marmoset", "marmot", "marten", "meerkat",
        "mongoose", "moorhen", "mudskipper", "narwhal", "newt", "nightjar", "numbat", "nuthatch",
        "ocelot", "octopus", "okapi", "opossum", "orangutan", "oriole", "osprey", "ostrich",
        "otter", "pangolin", "panther", "parakeet", "partridge", "peacock", "pelican", "penguin",
        "petrel", "pheasant", "pika", "platypus", "plover", "porcupine", "porpoise", "possum",
        "puffin", "puma", "quail", "quetzal", "quokka", "quoll", "raccoon", "raven",
        "reindeer", "rhinoceros", "roadrunner", "salamander", "sandpiper", "sawfish", "scallop", "seahorse",
        "serval", "shearwater", "shrew", "siskin", "skink", "skylark", "sloth", "snapper",
        "songbird", "sparrow", "spoonbill", "squid", "squirrel", "starling", "stingray", "stoat",
        "stork", "sturgeon", "sunfish", "swallow", "swift", "tamarin", "tanager", "tapir",
        "tarsier", "terrapin", "thrush", "tortoise", "toucan", "trout", "turtle", "umbrellabird",
        "urchin", "vicuna", "viper", "vireo", "vole", "wallaby", "walrus", "warbler",
        "wombat", "woodpecker", "wren", "yak", "zebra", "zebu"
    ];

    /** @var list<string> */
    static public $agents = [
        "admirer", "advocate", "aficionado", "alchemist", "alpinist", "ambassador", "analyst", "annotator",
        "apprentice", "arborist", "archer", "archivist", "arranger", "artisan", "astronomer", "auditor",
        "author", "aviator", "baker", "balloonist", "bandleader", "banjoist", "beekeeper", "believer",
        "benefactor", "bibliophile", "birder", "boatwright", "botanist", "brewer", "builder", "cabinetmaker",
        "calligrapher", "camper", "canoeist", "cartographer", "carver", "cataloguer", "caterer", "cellist",
        "champion", "chandler", "chemist", "chronicler", "cobbler", "collector", "colorist", "commentator",
        "compiler", "composer", "conductor", "connoisseur", "conservator", "cooper", "copyist", "coxswain",
        "crafter", "critic", "curator", "cyclist", "dabbler", "dancer", "defender", "delegate",
        "devotee", "diarist", "docent", "doodler", "drummer", "editor", "educator", "embroiderer",
        "emissary", "enjoyer", "enthusiast", "essayist", "etcher", "explorer", "fabulist", "fancier",
        "farrier", "fiddler", "finisher", "fletcher", "florist", "flutist", "folklorist", "forager",
        "forester", "founder", "gardener", "geologist", "glassblower", "gleaner", "grafter", "guide",
        "harpist", "harvester", "herald", "herbalist", "historian", "hobbyist", "horticulturist", "illustrator",
        "improviser", "innkeeper", "inventor", "investigator", "jeweler", "journaler", "juggler", "keeper",
        "kiteflyer", "knitter", "lapidary", "lexicographer", "librarian", "linguist", "lithographer", "luthier",
        "mapmaker", "mariner", "mason", "mediator", "mentor", "metalsmith", "meteorologist", "miller",
        "mimic", "minstrel", "modeler", "mountaineer", "muralist", "narrator", "naturalist", "navigator",
        "networker", "novelist", "observer", "optimist", "orchardist", "organizer", "ornithologist", "painter",
        "paleontologist", "papermaker", "partisan", "patron", "pedaler", "philosopher", "photographer", "pianist",
        "pilgrim", "pioneer", "planner", "planter", "poet", "potter", "preserver", "printer",
        "producer", "professor", "proofreader", "puzzler", "quilter", "rambler", "ranger", "reader",
        "reciter", "recorder", "referee", "reformer", "researcher", "restorer", "rhymer", "rigger",
        "rower", "sailor", "sampler", "scholar", "scribe", "sculptor", "seafarer", "shepherd",
        "shipwright", "sketcher", "skipper", "smith", "solver", "sommelier", "sorter", "spelunker",
        "stargazer", "steward", "stonecutter", "storyteller", "strategist", "surveyor", "swimmer", "tactician",
        "tailor", "tinkerer", "topiarist", "tracker", "trailblazer", "translator", "traveler", "tuner",
        "typist", "upholsterer", "vintner", "violinist", "voyager", "wanderer", "watchmaker", "weaver",
        "welder", "whistler", "whittler", "woodworker", "wordsmith", "wrangler", "yodeler", "zoologist"
    ];

    /** @return string */
    static function generate() {
        $a = self::$animals[mt_rand(0, count(self::$animals) - 1)];
        $g = self::$agents[mt_rand(0, count(self::$agents) - 1)];
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
